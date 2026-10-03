<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\NotificationProvider;
use App\Services\Notifications\Channels\EmailProvider;
use App\Services\Notifications\Channels\TelegramProvider;
use App\Services\Notifications\NotificationProviderRegistry;
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
        //
    }
}
