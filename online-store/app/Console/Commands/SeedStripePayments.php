<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Payment\CreateStripeIntent;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use InvalidArgumentException;
use Stripe\Exception\ApiErrorException;

/**
 * Opens real Stripe test PaymentIntents against already-seeded orders, so an
 * end-to-end payment simulation has real objects to drive.
 *
 * ## Why this is a separate command and not part of the seed chain
 *
 * `DemoOrderSeeder` produces payment rows through `RecordPayment` and walks
 * their statuses through `TransitionPaymentStatus` — real Actions, real
 * state machine, but no Stripe object anywhere. Every
 * `stripe_payment_intent_id` in a freshly seeded database is null. That is
 * the correct default: ADR-0003 makes seeding offline and deterministic, and
 * `migrate:fresh --seed` is a contract that must hold for anyone with no
 * Stripe key, in CI, and offline.
 *
 * So this is opt-in, exactly like `demo:fetch-images` — the established
 * shape here for "seeded data needs a real external call to be complete."
 * It makes network calls, needs a key nobody else's environment has, and is
 * wired into no seeder. `docs/explanation/demo-seeding.md` records why that
 * separation is the rule rather than this command's own exception.
 *
 * ## Why it goes through `CreateStripeIntent` rather than the Stripe SDK
 *
 * A command calling `StripeClient` directly would be a second
 * intent-creation path, and the interesting behaviour is all in the first
 * one: the amount read off the payment row rather than the caller (so the
 * figure traces back to a server-side total), the `lockForUpdate` re-read,
 * the `idempotency_key` derived from the payment id, and the `metadata` the
 * webhook matches back against. Running the Action is what makes this a
 * simulation of the real checkout rather than a fixture that happens to
 * contain intent ids.
 *
 * It also means re-running is safe: the Action returns the existing intent
 * for a payment that already has one, both from its own guard and from
 * Stripe's idempotency key.
 *
 * ## What it deliberately does not do
 *
 * It does not confirm the intents. A PaymentIntent reaches `succeeded` when
 * a card is confirmed against it — in the browser via Stripe.js, or with a
 * test payment method — and the app's own status only moves when the
 * resulting webhook arrives at `/stripe/webhook`. Both of those are the
 * things an end-to-end run is meant to exercise, so creating them here
 * would remove the point. This command sets up the board; it does not play
 * the game.
 *
 * **The webhook forwarding trap** applies to anything driving these
 * afterward: if the Stripe CLI is authenticated to a different account than
 * `STRIPE_SECRET` belongs to, `stripe listen` prints `Ready!` and forwards
 * nothing. `docs/how-to/troubleshooting.md`, "Stripe says a payment
 * succeeded and the app still shows it pending", has the account-pair check
 * and the direct-delivery workaround.
 */
class SeedStripePayments extends Command
{
    protected $signature = 'demo:stripe-payments
        {--limit=10 : How many payments to open an intent for}
        {--dry-run : Report what would be done without calling Stripe}';

    protected $description = 'Open real Stripe test PaymentIntents against seeded orders, for end-to-end simulation';

    public function handle(CreateStripeIntent $createStripeIntent): int
    {
        if (app()->isProduction()) {
            $this->error('demo:stripe-payments does not run in production.');

            return self::FAILURE;
        }

        $secretConfig = config('services.stripe.secret');

        if ($secretConfig !== null && ! is_scalar($secretConfig)) {
            throw new InvalidArgumentException('Config value [services.stripe.secret] must be a scalar value.');
        }

        $secret = (string) $secretConfig;

        if ($secret === '') {
            $this->error('STRIPE_SECRET is not set — nothing to call.');

            return self::FAILURE;
        }

        if (! str_starts_with($secret, 'sk_test_')) {
            // A live key here would open real PaymentIntents against real
            // seeded demo orders. Refused outright rather than warned about:
            // there is no correct reason for this command to hold one.
            $this->error('STRIPE_SECRET is not a test key (expected sk_test_…). Refusing to run.');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));

        $payments = $this->candidates($limit);

        if ($payments->isEmpty()) {
            $this->warn('No eligible payments found.');
            $this->line('Eligible means: method=stripe, status in (pending, processing), and no intent yet.');
            $this->line('Seed the demo dataset first: <comment>php artisan demo:seed --fresh</comment>');

            return self::SUCCESS;
        }

        $this->info("Found {$payments->count()} eligible payment(s).");

        if ($this->option('dry-run')) {
            foreach ($payments as $payment) {
                $paymentKey = $payment->getKey();

                if (! is_scalar($paymentKey)) {
                    throw new InvalidArgumentException('Payment::getKey() returned a non-scalar value.');
                }

                $this->line("  would open an intent for payment #{$paymentKey} — {$payment->amount} {$payment->currency}");
            }

            $this->info('Dry run — nothing was sent to Stripe.');

            return self::SUCCESS;
        }

        $opened = 0;
        $failed = 0;
        $staleKeys = 0;

        foreach ($payments as $payment) {
            $paymentKey = $payment->getKey();

            if (! is_scalar($paymentKey)) {
                throw new InvalidArgumentException('Payment::getKey() returned a non-scalar value.');
            }

            try {
                $result = $createStripeIntent->handle($payment);

                $this->line("  payment #{$paymentKey} → {$result->stripe_payment_intent_id}");
                $opened++;
            } catch (ApiErrorException $e) {
                if ($this->isStaleIdempotencyKey($e)) {
                    // Not a defect, and specifically not one to "fix" in
                    // `CreateStripeIntent`: its idempotency key is
                    // `payment-intent-{id}`, which is exactly what stops a
                    // reloaded checkout charging a customer twice. Payment
                    // ids restart at 1 on every `migrate:fresh`, while
                    // Stripe remembers a key account-wide for 24 hours — so
                    // after a re-seed, low ids collide with the *previous*
                    // seed's payments, which had different amounts.
                    //
                    // Reported distinctly rather than counted as a failure,
                    // because the run is not broken and the next re-seed
                    // within the window will do the same thing again.
                    $this->warn("  payment #{$paymentKey} skipped — idempotency key already used by a previous seed (expires within 24h).");
                    $staleKeys++;

                    continue;
                }

                // Stripe refused this one. Report and continue: a single
                // declined or malformed intent should not abandon the rest,
                // and the summary below is what says how many landed.
                $this->error("  payment #{$paymentKey} failed at Stripe: ".$e->getMessage());
                $failed++;
            } catch (\Throwable $e) {
                $this->error("  payment #{$paymentKey} failed: ".$e->getMessage());
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Opened {$opened} intent(s), {$failed} failed.");

        if ($staleKeys > 0) {
            $this->warn(
                "{$staleKeys} payment(s) skipped on a re-used idempotency key. Re-run with a larger "
                .'--limit to reach payments with higher ids, or wait out the 24h window.'
            );
        }

        if ($opened > 0) {
            $this->newLine();
            $this->line('These intents are created, not confirmed. To drive one end to end:');
            $this->line('  1. Confirm it with a test card (4242… succeeds, 4000 0025 0000 3155 needs 3DS).');
            $this->line('  2. Deliver the resulting event to <comment>/stripe/webhook</comment>.');
            $this->line('Check the CLI/app account pair first — see troubleshooting.md, "Stripe says a payment succeeded".');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Whether a Stripe refusal is the re-seeded-database idempotency
     * collision rather than a real problem with this payment.
     *
     * Matched on the error code Stripe returns for it, with the message as a
     * fallback — the code is the stable half, but older API versions only
     * carried the text.
     */
    private function isStaleIdempotencyKey(ApiErrorException $e): bool
    {
        if ($e->getStripeCode() === 'idempotency_key_in_use') {
            return true;
        }

        return str_contains((string) $e->getMessage(), 'idempotent requests can only be used with the same parameters');
    }

    /**
     * Payments an intent can legitimately be opened against.
     *
     * The status filter mirrors `CreateStripeIntent`'s own guard rather than
     * trusting it to refuse: `Paid`, `Refunded`, `PartiallyRefunded` and
     * `Cancelled` all mean money has already moved or the attempt is over,
     * and `StripeIntentNotAllowedException` is the right answer for those.
     * Selecting them here would just turn every run into a wall of caught
     * refusals.
     *
     * @return EloquentCollection<int, Payment>
     */
    private function candidates(int $limit): EloquentCollection
    {
        /** @var EloquentCollection<int, Payment> $payments */
        $payments = Payment::query()
            ->where('method', PaymentMethod::Stripe)
            ->whereIn('status', [PaymentStatus::Pending, PaymentStatus::Processing])
            ->whereNull('stripe_payment_intent_id')
            ->with('order')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return $payments;
    }
}
