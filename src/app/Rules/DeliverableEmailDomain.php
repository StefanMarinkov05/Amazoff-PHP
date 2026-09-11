<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * The email's domain actually accepts mail — an MX (or A) record exists.
 *
 * Laravel's own `email:dns` does the same check and is what this replaces,
 * for one reason: it has no seam. Every test fixture in this codebase uses
 * `@example.test`, and `.test` is reserved by RFC 6761 precisely so it
 * never resolves, so turning `dns` on turned 21 checkout tests red. The
 * alternatives were to point the whole suite at a real domain — making CI
 * depend on a working resolver for tests that are not about DNS — or to
 * mock a framework internal. A rule of our own with an explicit config
 * switch is neither.
 *
 * `config('mail.verify_email_domain')` is false in `phpunit.xml` and true
 * everywhere else. When it is off this rule passes everything, and the
 * check itself is still covered directly by `DeliverableEmailDomainTest`,
 * which turns it on and asserts against a domain that resolves and one that
 * cannot.
 *
 * ## What this does and does not prove
 *
 * It proves the domain exists and publishes a mail record. It does **not**
 * prove the mailbox exists — only sending can establish that, and the
 * bounce it produces is a mail-provider concern (`explanation/
 * transactional-email.md`, "Not done"). What it catches is the common typo
 * class — `gmial.com`, `hotmial.com`, a fat-fingered TLD — which `email:rfc`
 * accepts happily because they are syntactically perfect.
 *
 * Used on checkout only. That is the one form where a wrong address costs
 * the customer the only record of a contract they paid for: the card path's
 * CRD Art. 8(7) confirmation is sent once, when payment lands (ADR-0022).
 * Putting a network call on login or the newsletter would add a slow
 * resource to a surface an attacker can loop, which is the opposite of what
 * the cart caps and rate limits are for.
 */
final class DeliverableEmailDomain implements ValidationRule
{
    /**
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (config('mail.verify_email_domain') !== true) {
            return;
        }

        if (! is_string($value) || $value === '') {
            // Shape is `email:rfc`'s job, and it runs alongside this rule.
            // Saying nothing here keeps one message per problem.
            return;
        }

        $domain = mb_strrchr($value, '@');

        if ($domain === false || mb_strlen($domain) < 2) {
            return;
        }

        $domain = mb_substr($domain, 1);

        // MX first, then A: a domain with no MX but a valid A record still
        // accepts mail at that host under RFC 5321 §5.1, and refusing it
        // would reject small self-hosted domains that genuinely work.
        if (checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'A')) {
            return;
        }

        $fail('We could not find a mail server for that address\' domain. Please check it for typos.');
    }
}
