<?php

declare(strict_types=1);

use App\Support\CookieConsent;
use Illuminate\Http\Request;

/*
 * The consent gate (ePrivacy Art. 5(3), ADR-0019). "Undecided" must count
 * as "not granted" — the conservative reading the regulation requires.
 */

function requestWithConsent(?string $value): Request
{
    $request = Request::create('/');

    if ($value !== null) {
        $request->cookies->set(CookieConsent::COOKIE, $value);
    }

    return $request;
}

it('grants only on an explicit accepted', function (): void {
    expect(CookieConsent::granted(requestWithConsent('accepted')))->toBeTrue()
        ->and(CookieConsent::granted(requestWithConsent('rejected')))->toBeFalse()
        ->and(CookieConsent::granted(requestWithConsent(null)))->toBeFalse()
        ->and(CookieConsent::granted(requestWithConsent('garbage')))->toBeFalse();
});

it('reports a decision only when one of the two known values is set', function (): void {
    expect(CookieConsent::decided(requestWithConsent('accepted')))->toBeTrue()
        ->and(CookieConsent::decided(requestWithConsent('rejected')))->toBeTrue()
        ->and(CookieConsent::decided(requestWithConsent(null)))->toBeFalse();
});
