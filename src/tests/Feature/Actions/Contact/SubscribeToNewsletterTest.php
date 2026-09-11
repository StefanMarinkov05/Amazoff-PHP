<?php

declare(strict_types=1);

use App\Actions\Contact\SubscribeToNewsletter;
use App\Enums\NewsletterStatus;
use App\Mail\NewsletterConfirmation;
use App\Models\NewsletterSubscriber;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/*
 * Double opt-in (ePrivacy Art. 13, ADR-0019). Submitting does not
 * subscribe — it creates a Pending row and queues a confirmation email.
 */

beforeEach(fn () => Mail::fake());

it('creates a pending row and queues a confirmation email for a new address', function (): void {
    $result = app(SubscribeToNewsletter::class)->handle('New@Example.test', null);

    expect($result['subscriber']->email)->toBe('new@example.test')   // normalised
        ->and($result['subscriber']->status)->toBe(NewsletterStatus::Pending)
        ->and($result['subscriber']->confirmation_token)->toHaveLength(64)
        ->and($result['subscriber']->confirmed_at)->toBeNull()
        ->and($result['confirmationSent'])->toBeTrue();

    Mail::assertQueued(
        NewsletterConfirmation::class,
        fn (NewsletterConfirmation $m): bool => $m->hasTo('new@example.test'),
    );
});

it('attributes the row to a signed-in actor', function (): void {
    $user = User::factory()->create();

    $result = app(SubscribeToNewsletter::class)->handle('member@example.test', $user);

    expect($result['subscriber']->user_id)->toBe($user->getKey());
});

it('does not re-email an address that is already subscribed', function (): void {
    NewsletterSubscriber::factory()->create(['email' => 'on@example.test']);

    $result = app(SubscribeToNewsletter::class)->handle('on@example.test', null);

    expect($result['subscriber']->status)->toBe(NewsletterStatus::Subscribed)
        ->and($result['confirmationSent'])->toBeFalse();

    Mail::assertNotQueued(NewsletterConfirmation::class);
});

it('re-consents an unsubscribed address with a fresh token and email', function (): void {
    $row = NewsletterSubscriber::factory()->unsubscribed()->create(['email' => 'back@example.test']);
    $oldToken = $row->confirmation_token;

    $result = app(SubscribeToNewsletter::class)->handle('back@example.test', null);

    expect($result['subscriber']->status)->toBe(NewsletterStatus::Pending)
        ->and($result['subscriber']->confirmation_token)->not->toBe($oldToken)
        ->and(NewsletterSubscriber::query()->where('email', 'back@example.test')->count())->toBe(1);

    Mail::assertQueued(NewsletterConfirmation::class);
});

it('reissues the token when a pending address is submitted again', function (): void {
    $row = NewsletterSubscriber::factory()->pending()->create(['email' => 'again@example.test']);
    $oldToken = $row->confirmation_token;

    app(SubscribeToNewsletter::class)->handle('again@example.test', null);

    expect(NewsletterSubscriber::query()->where('email', 'again@example.test')->value('confirmation_token'))
        ->not->toBe($oldToken);
});

it('claims a guest row for a user who registered after subscribing', function (): void {
    NewsletterSubscriber::factory()->create(['email' => 'guest@example.test', 'user_id' => null]);
    $user = User::factory()->create();

    $result = app(SubscribeToNewsletter::class)->handle('guest@example.test', $user);

    expect($result['subscriber']->user_id)->toBe($user->getKey());
});

it('does not unlink an existing owner when a guest re-submits', function (): void {
    $owner = User::factory()->create();
    NewsletterSubscriber::factory()->create(['email' => 'owned@example.test', 'user_id' => $owner->getKey()]);

    app(SubscribeToNewsletter::class)->handle('owned@example.test', null);

    expect(NewsletterSubscriber::query()->where('email', 'owned@example.test')->value('user_id'))
        ->toBe($owner->getKey());
});

it('writes one row when the same address is submitted twice', function (): void {
    $action = app(SubscribeToNewsletter::class);
    $action->handle('twice@example.test', null);
    $action->handle('twice@example.test', null);

    expect(NewsletterSubscriber::query()->where('email', 'twice@example.test')->count())->toBe(1);
});
