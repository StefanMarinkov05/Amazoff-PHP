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
 * The double opt-in confirmation email — ePrivacy Art. 13, ADR-0019.
 * `explanation/transactional-email.md`.
 *
 * Queued from `SubscribeToNewsletter`. The address is not on the list until
 * the link here is clicked. Carries an unsubscribe link too, so a
 * mis-entered address can opt out without ever confirming.
 */
final class NewsletterConfirmation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public NewsletterSubscriber $subscriber) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirm your newsletter subscription');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.newsletter.confirmation',
            with: [
                'confirmUrl' => route('newsletter.confirm', ['token' => $this->subscriber->confirmation_token]),
                'unsubscribeUrl' => route('newsletter.unsubscribe', ['token' => $this->subscriber->confirmation_token]),
            ],
        );
    }
}
