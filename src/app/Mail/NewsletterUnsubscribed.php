<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\NewsletterSubscriber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Acknowledges an unsubscribe (GDPR Art. 7(3), ADR-0019).
 * `explanation/transactional-email.md`.
 *
 * Queued from `UnsubscribeFromNewsletter` once, on the transition to
 * `Unsubscribed`. Confirms the address is off the list and offers a
 * re-subscribe link, which starts the double opt-in over.
 */
final class NewsletterUnsubscribed extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public NewsletterSubscriber $subscriber) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'You have been unsubscribed');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.newsletter.unsubscribed',
            with: ['resubscribeUrl' => route('home')],
        );
    }
}
