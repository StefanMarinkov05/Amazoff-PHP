<?php

declare(strict_types=1);

use App\Actions\Contact\SubscribeToNewsletter;
use App\Enums\NewsletterStatus;
use App\Models\NewsletterSubscriber;
use App\Models\User;

/*
 * The Action exists because `NewsletterSubscriberForm` writes `status` too,
 * so re-subscribing has to mean the same thing from the footer and from the
 * panel. The resurrection case below is the whole reason it is not an inline
 * `create()`, and the duplicate case pins the idempotency shape CLAUDE.md
 * requires - a caught UNIQUE violation, never `exists()` then insert.
 */

it('subscribes a new address', function (): void {
    $subscriber = app(SubscribeToNewsletter::class)->handle('new@example.test', null);

    expect($subscriber->email)->toBe('new@example.test')
        ->and($subscriber->status)->toBe(NewsletterStatus::Subscribed)
        ->and($subscriber->subscribed_at)->not->toBeNull()
        ->and($subscriber->user_id)->toBeNull();
});

it('attributes the row to the actor when one is signed in', function (): void {
    $user = User::factory()->create();

    $subscriber = app(SubscribeToNewsletter::class)->handle('member@example.test', $user);

    expect($subscriber->user_id)->toBe($user->getKey());
});

it('resurrects an unsubscribed address instead of failing', function (): void {
    NewsletterSubscriber::factory()->create([
        'email' => 'back@example.test',
        'status' => NewsletterStatus::Unsubscribed,
    ]);

    $subscriber = app(SubscribeToNewsletter::class)->handle('back@example.test', null);

    expect($subscriber->status)->toBe(NewsletterStatus::Subscribed)
        ->and(NewsletterSubscriber::query()->where('email', 'back@example.test')->count())->toBe(1);
});

it('claims a guest row for the user who later signs in', function (): void {
    // The erasure routine finds rows by user, so a subscription made before
    // registering stays unreachable until re-subscribing links it up.
    NewsletterSubscriber::factory()->create([
        'email' => 'guest@example.test',
        'user_id' => null,
    ]);

    $user = User::factory()->create();

    $subscriber = app(SubscribeToNewsletter::class)->handle('guest@example.test', $user);

    expect($subscriber->user_id)->toBe($user->getKey());
});

it('does not unlink an existing owner when a guest re-subscribes', function (): void {
    $owner = User::factory()->create();

    NewsletterSubscriber::factory()->create([
        'email' => 'owned@example.test',
        'user_id' => $owner->getKey(),
    ]);

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
