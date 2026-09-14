<?php

declare(strict_types=1);

use App\Rules\DeliverableEmailDomain;
use Illuminate\Support\Facades\Validator;

/*
 * The rule's own coverage, and the reason the config switch exists: every
 * other test in the suite runs with `mail.verify_email_domain` false (see
 * phpunit.xml), because `@example.test` never resolves by design. This file
 * turns it back on, so the lookup is hidden from tests that are not about
 * it without being hidden from the one that is.
 *
 * These cases do real DNS. That is deliberate — a mocked resolver would
 * prove the mock works — but it means this file, alone in the suite, needs
 * a working resolver. `example.com` and `invalid.` are both stable choices:
 * the former is reserved by RFC 2606 and permanently registered with a mail
 * record, the latter is reserved by RFC 6761 as guaranteed never to resolve.
 */

function validateEmailDomain(string $email): bool
{
    return Validator::make(
        ['email' => $email],
        ['email' => [new DeliverableEmailDomain]],
    )->passes();
}

it('passes everything while verification is switched off', function (): void {
    config(['mail.verify_email_domain' => false]);

    // The state every other test in the suite runs in. Without this the
    // whole checkout suite would depend on DNS.
    expect(validateEmailDomain('ada@example.test'))->toBeTrue()
        ->and(validateEmailDomain('ada@definitely-not-a-real-domain-xyz.invalid'))->toBeTrue();
});

it('accepts an address whose domain publishes a mail record', function (): void {
    config(['mail.verify_email_domain' => true]);

    expect(validateEmailDomain('ada@example.com'))->toBeTrue();
});

it('refuses an address whose domain cannot receive mail', function (): void {
    config(['mail.verify_email_domain' => true]);

    // `.invalid` is reserved by RFC 6761 as guaranteed never to resolve —
    // the typo class this rule exists for, in its most certain form.
    expect(validateEmailDomain('ada@definitely-not-a-real-domain-xyz.invalid'))->toBeFalse();
});

it('says what the customer should do about it', function (): void {
    config(['mail.verify_email_domain' => true]);

    $validator = Validator::make(
        ['email' => 'ada@definitely-not-a-real-domain-xyz.invalid'],
        ['email' => [new DeliverableEmailDomain]],
    );

    // A message naming the likely cause, not "the email field is invalid" —
    // the customer's next action is to re-read what they typed.
    expect($validator->errors()->first('email'))->toContain('typo');
});

/*
 * Shape is `email:rfc`'s job and the two rules run together, so this one
 * stays silent on malformed input rather than producing a second message
 * for the same problem.
 */
it('leaves malformed input to the syntax rule', function (): void {
    config(['mail.verify_email_domain' => true]);

    expect(validateEmailDomain('not-an-address'))->toBeTrue()
        ->and(validateEmailDomain(''))->toBeTrue();
});
