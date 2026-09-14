<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A rate limit for a public write.
 *
 * SEC-010: `Login` and `TrackOrder` each grew their own throttle because
 * throttling is part of the login idiom and part of an enumeration defence.
 * Nothing made it the default for an ordinary public form, so `ContactForm`,
 * `NewsletterSignup`, `Register` and `ChangePassword` had none — measured at
 * 12 of 12 newsletter signups and 8 of 8 contact messages accepted back to
 * back.
 *
 * The rule this encodes: **a rate limit is a property of the endpoint, not of
 * the feature.** A form that writes a row on an unauthenticated request needs
 * one whether or not anything about it feels security-shaped.
 *
 * The *key* is deliberately not decided here, because it differs per form and
 * getting it wrong is worse than having no limit at all — keying an
 * enumeration defence on the value being enumerated gives an attacker N
 * attempts *each*. Callers pass their own; see each call site for its
 * reasoning.
 */
trait ThrottlesSubmissions
{
    /**
     * Refuse the submission once `$maxAttempts` have been made against
     * `$key` within the decay window, and record this attempt.
     *
     * Counts every attempt rather than only failures, unlike `Login`: there
     * is no "wrong password" here to distinguish, and the thing being
     * limited is volume itself.
     *
     * @param  string  $errorField  Which field carries the message, so it
     *                              renders where the user is looking.
     *
     * @throws ValidationException
     */
    protected function throttleSubmission(
        string $key,
        string $errorField,
        int $maxAttempts = 5,
        int $decaySeconds = 60,
    ): void {
        $throttleKey = Str::transliterate($key);

        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                $errorField => __('Too many attempts. Please try again in :seconds seconds.', [
                    'seconds' => $seconds,
                ]),
            ]);
        }

        RateLimiter::hit($throttleKey, $decaySeconds);
    }

    /**
     * The requesting address, for keying a limit on a form no account is
     * behind. `unknown` rather than null so a request with no resolvable IP
     * shares one bucket instead of bypassing the limit entirely.
     */
    protected function requestIp(): string
    {
        return request()->ip() ?? 'unknown';
    }
}
