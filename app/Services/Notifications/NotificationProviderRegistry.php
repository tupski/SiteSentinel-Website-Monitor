<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Contracts\NotificationProvider;
use App\Services\Notifications\Channels\EmailProvider;
use App\Services\Notifications\Channels\TelegramProvider;
use App\Services\Notifications\Channels\WebPushProvider;
use InvalidArgumentException;

/**
 * type=>class map email/telegram/browser_push (NOTIFICATIONS.md §2.5, §7.3).
 *
 * Container resolution. Unknown type fails closed. Browser Push is a normal
 * provider here — the dispatcher/incident engine carry no push special-case
 * (ADR-010, ADR-032).
 */
final class NotificationProviderRegistry
{
    /**
     * @var array<string, class-string<NotificationProvider>>
     */
    private const MAP = [
        'email' => EmailProvider::class,
        'telegram' => TelegramProvider::class,
        'browser_push' => WebPushProvider::class,
    ];

    public static function known(string $type): bool
    {
        return isset(self::MAP[$type]);
    }

    /**
     * The provider class for a channel type, or null when unknown. Exposed so
     * callers (e.g. the admin test-send) can build an instance through the
     * container with the same class the registry would resolve.
     *
     * @return class-string<NotificationProvider>|null
     */
    public static function classFor(string $type): ?string
    {
        return self::MAP[$type] ?? null;
    }

    public function resolve(string $type): NotificationProvider
    {
        $class = self::MAP[$type] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException("Unknown notification channel type: {$type}.");
        }

        /** @var NotificationProvider $provider */
        $provider = app($class);

        return $provider;
    }
}
