<?php

declare(strict_types=1);

use App\Enums\PaymentMethod;

/*
 * `PaymentMethod`'s two behavioural questions, tested on the enum rather
 * than through the three call sites that ask them.
 *
 * Why they are on the enum at all: both used to be written inline as
 * `!== PaymentMethod::Stripe` (CheckoutPage, twice) and
 * `=== PaymentMethod::CashOnDelivery` (SendOrderPlacedConfirmation) — two
 * spellings of the same intent, in opposite directions. With two cases they
 * agree. With a third (a deposit, a wallet) they would disagree silently:
 * the negation sweeps the new method into "email at placement", the
 * equality sweeps it into "wait for payment", and nothing fails to compile.
 * A `match` with no default cannot be extended without answering.
 *
 * These are the two cases this file exists to defend, so it is deliberately
 * written against `PaymentMethod::cases()` rather than a hand-listed pair:
 * adding a case makes the datasets grow by themselves.
 */

it('answers both questions for every case, with no default arm to hide one', function (PaymentMethod $method): void {
    // A match() without a default throws \UnhandledMatchError on an
    // unanswered case. Calling both for every case is what turns that from
    // a runtime surprise into a failing test the moment a case is added.
    expect($method->concludesContractAtPlacement())->toBeBool()
        ->and($method->requiresOnlinePayment())->toBeBool();
})->with(PaymentMethod::cases());

it('concludes a cash-on-delivery contract at placement', function (): void {
    // There is no payment step that can fail, so the customer has bought
    // something the moment the order is placed — the CRD Art. 8(7)
    // confirmation goes out from CheckoutPage immediately.
    expect(PaymentMethod::CashOnDelivery->concludesContractAtPlacement())->toBeTrue();
});

it('does not conclude a card contract until the money arrives', function (): void {
    // ADR-0022: sending at placement is what told customers who closed the
    // Stripe tab that they had bought goods they never paid for.
    expect(PaymentMethod::Stripe->concludesContractAtPlacement())->toBeFalse();
});

it('keeps the gateway question separate from the contract question', function (): void {
    // They coincide today and must not be collapsed into one method: a
    // deposit or a wallet would need a gateway *and* plausibly conclude at
    // placement, which is precisely the case a single flag cannot express.
    expect(PaymentMethod::Stripe->requiresOnlinePayment())->toBeTrue()
        ->and(PaymentMethod::CashOnDelivery->requiresOnlinePayment())->toBeFalse();
});

it('sends exactly one confirmation per method, differing only in when', function (): void {
    // The rule the two call sites rely on: every method is confirmed once.
    // CheckoutPage sends for the methods that conclude at placement, and
    // SendOrderPlacedConfirmation sends for the rest, on reaching Paid.
    // A method landing in both, or neither, is the bug this guards.
    // Compared by backing value, not by enum instance: array_intersect
    // stringifies its operands and cannot stringify an enum.
    $atPlacement = collect(PaymentMethod::cases())
        ->filter(fn (PaymentMethod $m): bool => $m->concludesContractAtPlacement())
        ->map(fn (PaymentMethod $m): string => $m->value);

    $onPayment = collect(PaymentMethod::cases())
        ->reject(fn (PaymentMethod $m): bool => $m->concludesContractAtPlacement())
        ->map(fn (PaymentMethod $m): string => $m->value);

    expect($atPlacement->intersect($onPayment))->toBeEmpty()
        ->and($atPlacement->count() + $onPayment->count())->toBe(count(PaymentMethod::cases()))
        // Neither side may be empty, or one of the two send paths is dead
        // code and the "exactly once" claim is vacuous.
        ->and($atPlacement)->not->toBeEmpty()
        ->and($onPayment)->not->toBeEmpty();
});
