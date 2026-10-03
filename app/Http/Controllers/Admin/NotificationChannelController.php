<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Contracts\DeliveryResult;
use App\Contracts\NotificationPayload;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreNotificationChannelRequest;
use App\Http\Requests\UpdateNotificationChannelRequest;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\MessageRedactor;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationProviderRegistry;
use DateTimeImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Notification channel admin (Phase 7 Admin UI).
 *
 * CRUD + test-send + delivery-log index. Controllers orchestrate only;
 * validation in Form Requests, delivery in providers. Secrets never
 * prefilled; empty secret on update preserves existing value.
 */
final class NotificationChannelController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationProviderRegistry $registry,
    ) {}

    public function index(): View
    {
        $channels = NotificationChannel::query()
            ->orderBy('name')
            ->get()
            ->map(function (NotificationChannel $channel): NotificationChannel {
                $channel->setAttribute('failed_count', NotificationLog::query()
                    ->where('channel_id', $channel->id)
                    ->where('status', 'failed')
                    ->count());

                return $channel;
            });

        return view('admin.notifications.channels.index', ['channels' => $channels]);
    }

    public function create(): View
    {
        return view('admin.notifications.channels.form', [
            'channel' => null,
            'config' => [],
            'hasSecret' => false,
        ]);
    }

    public function store(StoreNotificationChannelRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $config = $this->buildConfig($validated);
        $secret = $this->extractSecret($validated);

        $errors = $this->validateViaProvider($validated['type'], $config, $secret);
        if ($errors !== []) {
            return back()->withErrors(['config' => $errors])->withInput();
        }

        $channel = NotificationChannel::query()->create([
            'type' => $validated['type'],
            'name' => $validated['name'],
            'enabled' => (bool) ($validated['enabled'] ?? false),
            'config' => $config,
            'secret_ref' => $secret !== '' ? $secret : null,
        ]);

        $this->audit->log('notification.channel_created', $request->user(), $channel, [
            'type' => $channel->type,
            'name' => $channel->name,
        ]);

        return redirect()
            ->route('admin.notifications.index')
            ->with('status', __('Channel created.'));
    }

    public function edit(NotificationChannel $channel): View
    {
        return view('admin.notifications.channels.form', [
            'channel' => $channel,
            'config' => is_array($channel->config) ? $channel->config : [],
            'hasSecret' => $channel->secret_ref !== null && $channel->secret_ref !== '',
        ]);
    }

    public function update(UpdateNotificationChannelRequest $request, NotificationChannel $channel): RedirectResponse
    {
        $validated = $request->validated();
        $config = $this->buildConfig($validated);
        $secret = $this->extractSecret($validated);

        // Preserve existing secret when field left empty (masked input).
        $effectiveSecret = $secret !== '' ? $secret : ($channel->secret_ref !== null ? (string) $channel->secret_ref : null);

        $errors = $this->validateViaProvider($validated['type'], $config, $effectiveSecret);
        if ($errors !== []) {
            return back()->withErrors(['config' => $errors])->withInput();
        }

        $channel->type = $validated['type'];
        $channel->name = $validated['name'];
        $channel->enabled = (bool) ($validated['enabled'] ?? false);
        $channel->config = $config;
        if ($secret !== '') {
            $channel->secret_ref = $secret;
        }
        $channel->save();

        $this->audit->log('notification.channel_updated', $request->user(), $channel, [
            'type' => $channel->type,
            'name' => $channel->name,
            'enabled' => $channel->enabled,
            'secret_rotated' => $secret !== '',
        ]);

        return redirect()
            ->route('admin.notifications.index')
            ->with('status', __('Channel updated.'));
    }

    public function destroy(Request $request, NotificationChannel $channel): RedirectResponse
    {
        $this->audit->log('notification.channel_deleted', $request->user(), $channel, [
            'type' => $channel->type,
            'name' => $channel->name,
        ]);

        $channel->delete();

        return redirect()
            ->route('admin.notifications.index')
            ->with('status', __('Channel deleted.'));
    }

    public function testSend(Request $request, NotificationChannel $channel): RedirectResponse
    {
        $config = is_array($channel->config) ? $channel->config : [];
        $secret = $channel->secret_ref !== null ? (string) $channel->secret_ref : null;

        try {
            $provider = $this->registry->resolve((string) $channel->type);
        } catch (\Throwable $e) {
            return back()->with('status', __('Test failed: unknown channel type.'));
        }

        $validation = $provider->validateConfig($config, $secret);
        if ($validation !== true) {
            NotificationLog::create([
                'incident_id' => null,
                'channel_id' => $channel->id,
                'status' => 'failed',
                'attempt' => 1,
                'error' => MessageRedactor::redact('test-send config invalid'),
                'dedupe_key' => NotificationDispatcher::EVENT_TEST.':channel:'.$channel->id.':'.time(),
            ]);

            return back()->with('status', __('Test failed: channel misconfigured.'));
        }

        $payload = new NotificationPayload(
            incident_id: 0,
            website_id: 0,
            event_kind: NotificationDispatcher::EVENT_TEST,
            severity: 'WARNING',
            title: 'SiteSentinel channel test',
            summary: 'Test notification from SiteSentinel admin.',
            detected_at: new DateTimeImmutable('now'),
            admin_url: NotificationDispatcher::adminUrl(0),
            dedupe_key: NotificationDispatcher::EVENT_TEST.':channel:'.$channel->id.':'.time(),
            template_vars: [
                'website' => ['name' => 'SiteSentinel test', 'url' => (string) config('app.url')],
                'severity' => 'WARNING',
                'incident_type' => 'test',
                'score' => 0,
                'summary' => 'Test notification from SiteSentinel admin.',
                'detected_at' => now()->toIso8601String(),
                'incident_id' => 0,
                'current_status' => 'TEST',
                'admin_url' => NotificationDispatcher::adminUrl(0),
                'event_kind' => NotificationDispatcher::EVENT_TEST,
            ],
        );

        try {
            $live = app($provider::class, ['channelConfig' => $config, 'channelSecret' => $secret]);
            $result = $live->send($payload);
        } catch (\Throwable $e) {
            report($e);
            $result = new DeliveryResult(ok: false, error_code: 'provider_exception', error_message: MessageRedactor::redact($e->getMessage()), retryable: true);
        }

        NotificationLog::create([
            'incident_id' => null,
            'channel_id' => $channel->id,
            'status' => $result->ok ? 'sent' : 'failed',
            'attempt' => 1,
            'provider_message_id' => $result->provider_message_id,
            'error' => $result->ok ? null : MessageRedactor::redact(
                ($result->error_code !== null ? '['.$result->error_code.'] ' : '').($result->error_message ?? 'test-send failed')
            ),
            'dedupe_key' => $payload->dedupe_key,
            'sent_at' => $result->ok ? now() : null,
        ]);

        $this->audit->log('notification.channel_tested', $request->user(), $channel, [
            'ok' => $result->ok,
            'error_code' => $result->error_code,
        ]);

        return back()->with('status', $result->ok
            ? __('Test notification sent.')
            : __('Test failed: '.($result->error_code ?? 'delivery failed').'.'));
    }

    public function logs(Request $request): View
    {
        $logs = NotificationLog::query()
            ->with(['channel:id,type,name', 'incident:id,website_id,severity,status'])
            ->when($request->filled('status') && in_array((string) $request->query('status'), ['queued', 'sent', 'failed', 'suppressed'], true), function ($query) use ($request): void {
                $query->where('status', (string) $request->query('status'));
            })
            ->when($request->filled('incident_id') && is_numeric($request->query('incident_id')), function ($query) use ($request): void {
                $query->where('incident_id', (int) $request->query('incident_id'));
            })
            ->when($request->filled('channel_id') && is_numeric($request->query('channel_id')), function ($query) use ($request): void {
                $query->where('channel_id', (int) $request->query('channel_id'));
            })
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $channels = NotificationChannel::query()->orderBy('name')->get(['id', 'type', 'name']);

        return view('admin.notifications.logs.index', [
            'logs' => $logs,
            'channels' => $channels,
            'filters' => $request->only(['status', 'incident_id', 'channel_id']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function buildConfig(array $validated): array
    {
        $type = (string) ($validated['type'] ?? '');

        if ($type === 'telegram') {
            $config = [
                'chat_id' => (string) ($validated['telegram_chat_id'] ?? ''),
            ];
            if (($validated['telegram_thread_id'] ?? '') !== '') {
                $config['message_thread_id'] = (string) $validated['telegram_thread_id'];
            }
        } else {
            $recipients = $validated['email_recipients'] ?? [];
            if (is_string($recipients)) {
                $recipients = array_map(trim(...), explode(',', $recipients));
            }
            $config = [
                'recipients' => array_values(array_filter(array_map('strval', (array) $recipients))),
                'host' => (string) ($validated['email_host'] ?? ''),
                'port' => (int) ($validated['email_port'] ?? 587),
                'username' => (string) ($validated['email_username'] ?? ''),
                'encryption' => (string) ($validated['email_encryption'] ?? 'tls'),
                'from_address' => (string) ($validated['email_from_address'] ?? ''),
                'from_name' => (string) ($validated['email_from_name'] ?? 'SiteSentinel'),
            ];
        }

        $minSeverity = strtoupper((string) ($validated['min_severity'] ?? 'WARNING'));
        $config['min_severity'] = in_array($minSeverity, ['WARNING', 'CRITICAL'], true) ? $minSeverity : 'WARNING';

        return $config;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function extractSecret(array $validated): string
    {
        return trim((string) ($validated['secret_ref'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function validateViaProvider(string $type, array $config, ?string $secret): array
    {
        try {
            $provider = $this->registry->resolve($type);
            $result = $provider->validateConfig($config, $secret);

            return $result === true ? [] : array_map('strval', (array) $result);
        } catch (\Throwable $e) {
            return ['Unknown channel type.'];
        }
    }
}
