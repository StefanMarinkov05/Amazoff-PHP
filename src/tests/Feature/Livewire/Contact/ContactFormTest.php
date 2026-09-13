<?php

declare(strict_types=1);

use App\Livewire\Contact\ContactForm;
use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/*
 * The public contact form writes straight to `contact_messages` with no
 * account behind it, so its abuse defences — a honeypot and a per-IP rate
 * limit — are the whole of what is worth testing here (the validation
 * rules are ordinary Laravel and covered by the framework). Each case goes
 * red if its mechanism is removed from ContactForm::submit().
 */

beforeEach(fn () => RateLimiter::clear('contact|127.0.0.1'));

it('writes a message for a genuine submission', function (): void {
    Livewire::test(ContactForm::class)
        ->set('name', 'Grace Hopper')
        ->set('email', 'grace@example.test')
        ->set('subject', 'Hello')
        ->set('message', 'This is a real enquiry with enough length.')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('sent', true);

    expect(ContactMessage::where('email', 'grace@example.test')->exists())->toBeTrue();
});

it('silently discards a submission that fills the honeypot, writing nothing', function (): void {
    Livewire::test(ContactForm::class)
        ->set('name', 'Bot')
        ->set('email', 'bot@example.test')
        ->set('message', 'buy cheap things at example dot com right now')
        ->set('website', 'http://spam.example')
        ->call('submit')
        // Same "sent" state a real visitor sees — a bot learns nothing.
        ->assertSet('sent', true);

    expect(ContactMessage::where('email', 'bot@example.test')->exists())->toBeFalse();
});

it('does not spend the rate limit on a honeypot hit', function (): void {
    // Six honeypot submissions, then a genuine one — the genuine one must
    // still go through, because the honeypot returns before throttling.
    for ($i = 0; $i < 6; $i++) {
        Livewire::test(ContactForm::class)
            ->set('name', 'Bot')
            ->set('email', "bot{$i}@example.test")
            ->set('message', 'spam spam spam spam spam')
            ->set('website', 'x')
            ->call('submit');
    }

    Livewire::test(ContactForm::class)
        ->set('name', 'Real Person')
        ->set('email', 'real@example.test')
        ->set('message', 'A genuine message of sufficient length.')
        ->call('submit')
        ->assertHasNoErrors();

    expect(ContactMessage::where('email', 'real@example.test')->exists())->toBeTrue();
});

it('throttles the sixth genuine submission from one IP within a minute', function (): void {
    for ($i = 0; $i < 5; $i++) {
        Livewire::test(ContactForm::class)
            ->set('name', "Person {$i}")
            ->set('email', "person{$i}@example.test")
            ->set('message', 'A genuine message of sufficient length.')
            ->call('submit')
            ->assertHasNoErrors();
    }

    Livewire::test(ContactForm::class)
        ->set('name', 'One Too Many')
        ->set('email', 'sixth@example.test')
        ->set('message', 'A genuine message of sufficient length.')
        ->call('submit')
        ->assertHasErrors(['message']);

    expect(ContactMessage::where('email', 'sixth@example.test')->exists())->toBeFalse();
    expect(ContactMessage::count())->toBe(5);
});

it('associates the row with a signed-in user', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ContactForm::class)
        ->set('name', $user->getFilamentName())
        ->set('email', $user->email)
        ->set('message', 'A genuine message from a signed-in customer.')
        ->call('submit')
        ->assertHasNoErrors();

    expect(ContactMessage::latest('id')->first()->user_id)->toBe($user->id);
});
