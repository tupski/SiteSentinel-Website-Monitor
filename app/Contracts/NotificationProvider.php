<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Provider-independent notification contract (NOTIFICATIONS.md §2.3, ADR-010).
 *
 * The incident engine knows nothing about transports. Every provider
 * (Email, Telegram, future WhatsApp/Webhook) implements this boundary.
 */
interface NotificationProvider
{
    /**
     * Deliver one already-rendered payload. Must not throw for expected
     * delivery failures; return a DeliveryResult so the dispatcher owns
     * retry classification and logging.
     */
    public function send(NotificationPayload $payload): DeliveryResult;

    /**
     * Declare which event kinds this provider can express.
     */
    public function supports(string $eventKind): bool;

    /**
     * Validate channel configuration at save time and test-send time
     * without sending live traffic. Returns true when valid, otherwise
     * an array of human-readable error strings.
     *
     * @param  array<string, mixed>  $config
     */
    public function validateConfig(array $config, ?string $secret): bool|array;
}
