<?php

declare(strict_types=1);

namespace App\Mail;

use App\Filament\Resources\ContactMessages\ContactMessageResource;
use App\Models\ContactMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the shop inbox a contact message arrived, with a link to the panel
 * page that marks it handled. `explanation/transactional-email.md`.
 */
final class ContactMessageReceived extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public ContactMessage $contactMessage) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->contactMessage->email, $this->contactMessage->name)],
            subject: 'New contact message: '.($this->contactMessage->subject ?? 'no subject'),
        );
    }

    public function content(): Content
    {
        $message = $this->contactMessage;

        $body = "From:    {$message->name} <{$message->email}>\n"
            .'Subject: '.($message->subject ?? '—')."\n\n"
            .$message->message;

        // The sender's text goes inside a code fence so Markdown in it cannot
        // render as a link. The fence must outrun any backtick run in the text,
        // or the sender can close it.
        preg_match_all('/`+/', $body, $runs);
        $longestRun = max([0, ...array_map(strlen(...), $runs[0])]);

        return new Content(
            markdown: 'mail.contact.received',
            with: [
                'fence' => str_repeat('`', max(3, $longestRun + 1)),
                'body' => $body,
                'panelUrl' => ContactMessageResource::getUrl('view', ['record' => $message], panel: 'admin'),
            ],
        );
    }
}
