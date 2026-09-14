<?php

declare(strict_types=1);

use App\Actions\Contact\ConfirmNewsletterSubscription;
use App\Actions\Contact\PurgeUnconfirmedSubscribers;
use App\Actions\Contact\UnsubscribeFromNewsletter;
use App\Enums\NewsletterStatus;
use App\Mail\NewsletterUnsubscribed;
use App\Models\NewsletterSubscriber;
use Illuminate\Support\Facades\Mail;

/*
 * The confirm / unsubscribe / purge half of the double opt-in (ADR-0019).
 */

beforeEach(fn () => Mail::fake());

it('confirms a pending subscriber by token', function (): void {
    $row = NewsletterSubscriber::factory()->pending()->create();

    $result = app(ConfirmNewsletterSubscription::class)->handle($row->confirmation_token);

    expect($result?->status)->toBe(NewsletterStatus::Subscribed)
        ->and($result?->confirmed_at)->not->toBeNull();
});

it('is a no-op on a token that is already confirmed', function (): void {
    $row = NewsletterSubscriber::factory()->create();   // Subscribed

    expect(app(ConfirmNewsletterSubscription::class)->handle($row->confirmation_token)?->status)
        ->toBe(NewsletterStatus::Subscribed);
});

it('returns null for an unknown or unsubscribed token', function (): void {
    $unsub = NewsletterSubscriber::factory()->unsubscribed()->create();

    expect(app(ConfirmNewsletterSubscription::class)->handle('nope'))->toBeNull()
        ->and(app(ConfirmNewsletterSubscription::class)->handle($unsub->confirmation_token))->toBeNull();
});

it('unsubscribes by token and emails an acknowledgement', function (): void {
    $row = NewsletterSubscriber::factory()->create(['email' => 'off@example.test']);

    $result = app(UnsubscribeFromNewsletter::class)->handle($row->confirmation_token);

    expect($result?->status)->toBe(NewsletterStatus::Unsubscribed);
    Mail::assertQueued(NewsletterUnsubscribed::class, fn ($m) => $m->hasTo('off@example.test'));
});

it('does not re-acknowledge an already-unsubscribed token', function (): void {
    $row = NewsletterSubscriber::factory()->unsubscribed()->create();

    app(UnsubscribeFromNewsletter::class)->handle($row->confirmation_token);

    Mail::assertNotQueued(NewsletterUnsubscribed::class);
});

it('purges pending rows past the 30-day grace period, keeping recent and confirmed ones', function (): void {
    $stale = NewsletterSubscriber::factory()->pending()->create(['subscribed_at' => now()->subDays(31)]);
    $recent = NewsletterSubscriber::factory()->pending()->create(['subscribed_at' => now()->subDays(5)]);
    $confirmed = NewsletterSubscriber::factory()->create(['subscribed_at' => now()->subYears(2)]);

    $deleted = app(PurgeUnconfirmedSubscribers::class)->handle();

    expect($deleted)->toBe(1)
        ->and(NewsletterSubscriber::find($stale->id))->toBeNull()
        ->and(NewsletterSubscriber::find($recent->id))->not->toBeNull()
        ->and(NewsletterSubscriber::find($confirmed->id))->not->toBeNull();
});

it('exposes confirm and unsubscribe as routes', function (): void {
    $row = NewsletterSubscriber::factory()->pending()->create();

    $this->get(route('newsletter.confirm', ['token' => $row->confirmation_token]))
        ->assertOk()
        ->assertSee('Subscription confirmed');

    expect($row->refresh()->status)->toBe(NewsletterStatus::Subscribed);

    $this->get(route('newsletter.unsubscribe', ['token' => $row->confirmation_token]))
        ->assertOk()
        ->assertSee('unsubscribed');

    expect($row->refresh()->status)->toBe(NewsletterStatus::Unsubscribed);
});
