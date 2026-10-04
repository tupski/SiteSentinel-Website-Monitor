<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\NotificationProvider;
use App\Services\Audit\PruningAuditRecorder;
use App\Services\Notifications\Channels\EmailProvider;
use App\Services\Notifications\Channels\TelegramProvider;
use App\Services\Notifications\NotificationProviderRegistry;
use App\Services\Settings\SettingsRepository;
use Illuminate\Database\Events\ModelPruningFinished;
use Illuminate\Database\Events\ModelPruningStarting;
use Illuminate\Database\Events\ModelsPruned;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(NotificationProviderRegistry::class);

        // System settings store (ADR-035): a singleton so the resolved map is
        // memoized once per request on top of the version-counter cache.
        $this->app->singleton(SettingsRepository::class);

        // Provider registry: type=>class map email/telegram. Container
        // resolution; unknown type fails closed in the dispatcher.
        $this->app->bind(EmailProvider::class, fn ($app, array $params = []): EmailProvider => new EmailProvider(
            is_array($params['channelConfig'] ?? null) ? $params['channelConfig'] : [],
            is_string($params['channelSecret'] ?? null) ? $params['channelSecret'] : null,
        ));
        $this->app->bind(TelegramProvider::class, fn ($app, array $params = []): TelegramProvider => new TelegramProvider(
            is_array($params['channelConfig'] ?? null) ? $params['channelConfig'] : [],
            is_string($params['channelSecret'] ?? null) ? $params['channelSecret'] : null,
        ));
        $this->app->bind('notification.provider.email', EmailProvider::class);
        $this->app->bind('notification.provider.telegram', TelegramProvider::class);

        $this->app->bind(NotificationProvider::class, function (): NotificationProvider {
            throw new \InvalidArgumentException('Resolve a concrete notification provider via NotificationProviderRegistry.');
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Local development: `php artisan dev` runs the HTTP server, a queue
        // listener, and Vite — but NOT the scheduler. Without a scheduler and a
        // worker, the monitoring plane silently stalls: the every-minute sweep
        // that dispatches due checks never runs, and any job that IS dispatched
        // sits unconsumed on the queue (a website's "last check" freezes). Add
        // `schedule:work` to the dev process group so a single command brings up
        // the complete local stack. Guarded to console so web requests are
        // unaffected, and registered by userland code so it outranks the
        // framework default and survives upgrades.
        if ($this->app->runningInConsole()) {
            DevCommands::artisan('schedule:work', 'scheduler');

            // The framework default dev queue command listens only to the
            // `default` queue, so notifications (pinned to `notifications`)
            // would sit undelivered locally. Register the same name with a
            // userland priority so it replaces the default and drains both.
            DevCommands::artisan('queue:listen --queue=default,notifications --tries=1 --timeout=0', 'queue');
        }

        // SECURITY.md §9.1: the retention pruner (`model:prune`, scheduled daily)
        // must emit a `retention.pruned` audit event with per-model counts. The
        // recorder listens to Laravel's pruning lifecycle events so the audit
        // row is written once per run, without touching the built-in command.
        //
        // The recorder is a singleton so the counts it accumulates from the
        // per-model `ModelsPruned` events survive until `ModelPruningFinished`.
        $this->app->singleton(PruningAuditRecorder::class);

        Event::listen(
            ModelPruningStarting::class,
            fn (ModelPruningStarting $event) => app(PruningAuditRecorder::class)->onStarting($event),
        );
        Event::listen(
            ModelsPruned::class,
            fn (ModelsPruned $event) => app(PruningAuditRecorder::class)->onModelsPruned($event),
        );
        Event::listen(
            ModelPruningFinished::class,
            fn (ModelPruningFinished $event) => app(PruningAuditRecorder::class)->onFinished($event),
        );
    }
}
