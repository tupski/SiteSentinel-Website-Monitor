<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Contracts\DeliveryResult;
use App\Contracts\NotificationPayload;
use App\Contracts\NotificationProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreNotificationChannelRequest;
use App\Http\Requests\TestNotificationChannelRequest;
use App\Http\Requests\UpdateNotificationChannelRequest;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Services\Audit\AuditEvent;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\AdminNotificationService;
use App\Services\Notifications\MessageRedactor;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationProviderRegistry;
use App\Support\PerPage;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
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
        private readonly AdminNotificationService $adminNotifications,
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

        return view('admin.notifications.channels.index', [
            'channels' => $channels,
            // Only the VAPID *public* key is exposed to the opt-in JS; the
            // private key never leaves config (SECURITY.md §4, ADR-032).
            'pushPublicKey' => (string) config('sentinel.push.vapid_public_key', ''),
            'pushEnabled' => NotificationChannel::query()
                ->where('type', 'browser_push')
                ->where('enabled', true)
                ->exists(),
        ]);
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

        $this->adminNotifications->recordNotificationConfigChanged(
            'channel_created',
            'Notification channel “'.$channel->name.'” was created.',
            null,
            (int) $channel->id,
            $request->user()?->getKey(),
        );

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

        // SECURITY.md §9.1 canonical event: a channel's `secret_ref` change is a
        // security-relevant event distinct from a config edit. Emit it only when
        // the secret was actually rotated; metadata carries no secret material.
        if ($secret !== '') {
            $this->audit->log(AuditEvent::CHANNEL_SECRET_UPDATED, $request->user(), $channel, [
                'type' => $channel->type,
            ]);
        }

        $this->adminNotifications->recordNotificationConfigChanged(
            'channel_updated',
            'Notification channel “'.$channel->name.'” was updated.',
            null,
            (int) $channel->id,
            $request->user()?->getKey(),
        );

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

        $channelName = $channel->name;
        $channelId = (int) $channel->id;
        $channel->delete();

        $this->adminNotifications->recordNotificationConfigChanged(
            'channel_deleted',
            'Notification channel “'.$channelName.'” was deleted.',
            null,
            $channelId,
            $request->user()?->getKey(),
        );

        return redirect()
            ->route('admin.notifications.index')
            ->with('status', __('Channel deleted.'));
    }

    public function testSend(Request $request, NotificationChannel $channel): RedirectResponse
    {
        $config = is_array($channel->config) ? $channel->config : [];
        $secret = $channel->secret_ref !== null ? (string) $channel->secret_ref : null;

        $provider = $this->resolveProvider((string) $channel->type);
        if ($provider === null) {
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

        $payload = $this->buildTestPayload((int) $channel->id);
        $result = $this->deliver((string) $channel->type, $config, $secret, $payload);

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

        $this->audit->log(AuditEvent::CHANNEL_TESTED, $request->user(), $channel, [
            'ok' => $result->ok,
            'error_code' => $result->error_code,
        ]);

        $this->adminNotifications->recordNotificationConfigChanged(
            'channel_tested',
            'Notification channel “'.$channel->name.'” was tested: '.($result->ok ? 'succeeded' : 'failed').'.',
            null,
            (int) $channel->id,
            $request->user()?->getKey(),
        );

        return back()->with('status', $result->ok
            ? __('Test notification sent.')
            : __('Test failed: '.($result->error_code ?? 'delivery failed').'.'));
    }

    /**
     * Send a test through the real provider for an *unsaved* channel config
     * (Plan S3, NOTIFICATIONS.md §14.1) without persisting a channel.
     *
     * Goes through the same registry + provider contract as real sends, so the
     * escaping/truncation/transport path is identical. Writes a `channel.test`
     * notification_logs row scoped to the test (channel_id is null — nothing
     * persisted). Secrets never appear in the response or the log.
     */
    public function test(TestNotificationChannelRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $type = (string) $validated['type'];
        $config = $this->buildConfig($validated);
        $secret = $this->extractSecret($validated);

        if ($secret === '') {
            $secret = null;
        }

        // A channel row is persisted only after a successful test, so a test
        // for a config without a resolvable secret fails closed here.
        $provider = $this->resolveProvider($type);
        if ($provider === null) {
            return $this->testResponse($request, false, 'unknown_type', __('Test failed: unknown channel type.'));
        }

        $validation = $provider->validateConfig($config, $secret);
        if ($validation !== true) {
            return $this->testResponse($request, false, 'config_invalid', (string) ($validation[0] ?? __('Channel configuration is invalid.')));
        }

        $payload = $this->buildTestPayload(null);
        $result = $this->deliver($type, $config, $secret, $payload);

        // No channel to attach the log to; the row records the test attempt.
        NotificationLog::create([
            'incident_id' => null,
            'channel_id' => null,
            'status' => $result->ok ? 'sent' : 'failed',
            'attempt' => 1,
            'provider_message_id' => $result->provider_message_id,
            'error' => $result->ok ? null : MessageRedactor::redact(
                ($result->error_code !== null ? '['.$result->error_code.'] ' : '').($result->error_message ?? 'test-send failed')
            ),
            'dedupe_key' => $payload->dedupe_key,
            'sent_at' => $result->ok ? now() : null,
        ]);

        return $this->testResponse(
            $request,
            $result->ok,
            $result->error_code,
            $result->ok
                ? __('Test notification sent.')
                : __('Test failed: '.($result->error_code ?? 'delivery failed').'.')
        );
    }

    /**
     * Turbo-friendly: JSON for fetch/Turbo requests, otherwise back with a flash.
     */
    private function testResponse(Request $request, bool $ok, ?string $errorCode, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => $ok,
                'error_code' => $errorCode,
                'message' => $message,
            ]);
        }

        return back()->with('status', $message);
    }

    public function logs(Request $request): View
    {
        $query = NotificationLog::query()
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
            ->orderByDesc('id');

        $logs = $query
            ->paginate(PerPage::sizeFor($query, $request))
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

    /**
     * Resolve a provider through the registry, failing closed (null) for an
     * unknown type rather than leaking the exception to the client.
     */
    private function resolveProvider(string $type): ?NotificationProvider
    {
        try {
            return $this->registry->resolve($type);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The `channel.test` payload used by both the persisted and unsaved test
     * paths (NOTIFICATIONS.md §3, §14.1). Carries no secrets or evidence.
     *
     * @param  int|null  $channelId  null for an unsaved-config test.
     */
    private function buildTestPayload(?int $channelId): NotificationPayload
    {
        // Dedupe key is scoped to the scope (channel id or a timestamp for an
        // unsaved test) so it never collides with a real incident key.
        $scope = $channelId !== null ? 'channel:'.$channelId : 'unsaved:'.time();
        $dedupeKey = NotificationDispatcher::EVENT_TEST.':'.$scope.':'.time();

        return new NotificationPayload(
            incident_id: 0,
            website_id: 0,
            event_kind: NotificationDispatcher::EVENT_TEST,
            severity: 'WARNING',
            title: 'SiteSentinel channel test',
            summary: 'Test notification from SiteSentinel admin.',
            detected_at: new DateTimeImmutable('now'),
            admin_url: NotificationDispatcher::adminUrl(0),
            dedupe_key: $dedupeKey,
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
    }

    /**
     * Deliver a test through the provider registry's class for the given type,
     * so unsaved-config tests exercise the identical send path as a persisted
     * channel. Any provider exception is classified safely and redacted (never
     * leaked to the admin).
     *
     * @param  array<string, mixed>  $config
     */
    private function deliver(string $type, array $config, ?string $secret, NotificationPayload $payload): DeliveryResult
    {
        try {
            $providerClass = NotificationProviderRegistry::classFor($type);

            if ($providerClass === null) {
                return new DeliveryResult(ok: false, error_code: 'unknown_type', error_message: 'unknown channel type', retryable: false);
            }

            // A bound instance (tests bind a spy) takes precedence; otherwise
            // build a live provider with the supplied config/secret.
            /** @var NotificationProvider $live */
            $live = app()->bound($providerClass)
                ? app($providerClass)
                : app($providerClass, ['channelConfig' => $config, 'channelSecret' => $secret]);

            return $live->send($payload);
        } catch (\Throwable $e) {
            report($e);

            return new DeliveryResult(ok: false, error_code: 'provider_exception', error_message: MessageRedactor::redact($e->getMessage()), retryable: true);
        }
    }
}
