<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The one place that answers "may this visitor be served a non-essential
 * cookie?" — ePrivacy Art. 5(3), ADR-0019.
 *
 * The consent banner (`<x-site.cookie-consent>`) writes a first-party
 * `cookie_consent` cookie: `accepted`, `rejected`, or absent (undecided).
 * Any analytics / marketing / embed script this shop ever adds must gate on
 * `CookieConsent::granted()` — undecided counts as "not granted", the
 * conservative reading the regulation requires.
 *
 * There is nothing to gate yet: the app sets only the session and CSRF
 * cookies, which are strictly necessary and exempt. This exists so the
 * check is already the pattern when the first such script lands.
 */
final class CookieConsent
{
    public const COOKIE = 'cookie_consent';

    public static function granted(?Request $request = null): bool
    {
        $request ??= request();

        return $request->cookie(self::COOKIE) === 'accepted';
    }

    public static function decided(?Request $request = null): bool
    {
        $request ??= request();

        return in_array($request->cookie(self::COOKIE), ['accepted', 'rejected'], true);
    }
}
