<?php

declare(strict_types=1);

use App\Support\CookieConsent;

/*
 * The banner is rendered by the app layout and hidden once a choice cookie
 * is present (ADR-0019).
 */

it('shows the cookie notice to a visitor who has not chosen', function (): void {
    $this->get('/catalogue')
        ->assertOk()
        ->assertSee('Cookie notice', false)
        ->assertSee('Decline non-essential');
});

it('hides the notice once a choice cookie is present', function (): void {
    $this->withUnencryptedCookie(CookieConsent::COOKIE, 'accepted')
        ->get('/catalogue')
        ->assertOk()
        ->assertDontSee('Decline non-essential');
});

it('does not encrypt the consent cookie', function (): void {
    // The banner sets it from JavaScript, so EncryptCookies must skip it or
    // it is discarded as tampered on the next request.
    $this->withUnencryptedCookie(CookieConsent::COOKIE, 'rejected')
        ->get('/catalogue');

    expect(request()->cookie(CookieConsent::COOKIE))->toBe('rejected');
});
