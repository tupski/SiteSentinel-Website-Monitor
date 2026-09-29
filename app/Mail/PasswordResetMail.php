<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Password reset link mail (PLAN.md Phase 2, SECURITY.md §2.9).
 *
 * Plain-text by design: a reset link must survive stripped HTML in
 * text-only clients, and the URL is the only content that matters.
 * Sent synchronously at MVP (log/SMTP driver); queueing arrives with
 * the notification phase's dispatch policy.
 */
final class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $resetUrl,
        public readonly int $ttlMinutes
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'SiteSentinel password reset',
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.password-reset',
            with: [
                'resetUrl' => $this->resetUrl,
                'ttlMinutes' => $this->ttlMinutes,
            ],
        );
    }
}
