<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\NotificationProvider;
use App\Services\Audit\PruningAuditRecorder;
use App\Services\Notifications\Channels\EmailProvider;
use App\Services\Notifications\Channels\TelegramProvider;
use App\Services\Notifications\NotificationProviderRegistry;
use Illuminate\Database\Events\ModelPruningFinished;
use Illuminate\Database\Events\ModelPruningStarting;
use Illuminate\Database\Events\ModelsPruned;
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
