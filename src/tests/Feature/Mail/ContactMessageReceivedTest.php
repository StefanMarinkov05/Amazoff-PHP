<?php

declare(strict_types=1);

use App\Filament\Resources\ContactMessages\ContactMessageResource;
use App\Mail\ContactMessageReceived;
use App\Models\ContactMessage;

/*
 * The shop-inbox notification for a contact message. That it is queued from
 * the form is covered by ContactFormTest; this is the content contract.
 */

it('carries the sender, the message, and a link to its panel page', function (): void {
    $message = ContactMessage::factory()->create([
        'name' => 'Grace Hopper',
        'email' => 'grace@example.test',
        'subject' => 'Late delivery',
        'message' => 'My order has not arrived yet.',
    ]);

    $mail = new ContactMessageReceived($message);

    expect($mail->render())
        ->toContain('Grace Hopper')
        ->toContain('grace@example.test')
        ->toContain('Late delivery')
        ->toContain('My order has not arrived yet.')
        ->toContain(ContactMessageResource::getUrl('view', ['record' => $message], panel: 'admin'));

    $mail->assertHasReplyTo('grace@example.test', 'Grace Hopper');
    $mail->assertHasSubject('New contact message: Late delivery');
});

it('does not render a link written into the message as Markdown', function (string $text): void {
    $message = ContactMessage::factory()->create(['message' => $text]);

    expect((new ContactMessageReceived($message))->render())
        ->not->toContain('href="https://phish.example');
})->with([
    'plain Markdown link' => '[Reset your password](https://phish.example/reset)',
    'closes a triple-backtick fence first' => "```\n[Reset your password](https://phish.example/reset)\n```",
    'closes a longer fence first' => "`````\n[Reset your password](https://phish.example/reset)",
]);
