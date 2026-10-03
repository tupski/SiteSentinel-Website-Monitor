<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Contracts\NotificationProvider;
use App\Services\Notifications\Channels\EmailProvider;
use App\Services\Notifications\Channels\TelegramProvider;
use InvalidArgumentException;

/**
 * type=>class map email/telegram (NOTIFICATIONS.md §2.5).
 *
 * Container resolution. Unknown type fails closed.
 */
final class NotificationProviderRegistry
{
    /**
     * @var array<string, class-string<NotificationProvider>>
     */
    private const MAP = [
        'email' => EmailProvider::class,
        'telegram' => TelegramProvider::class,
    ];

    public static function known(string $type): bool
    {
        return isset(self::MAP[$type]);
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
