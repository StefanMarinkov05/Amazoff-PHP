<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use Stringable;

/**
 * A decimal amount, held as a string and never as a float.
 *
 * CLAUDE.md forbids float for money outright. Before this class that rule was
 * enforced by discipline — 30 `bc*` calls across 6 files, each repeating the
 * scale, each an opportunity to omit it and silently truncate. `bcadd($a, $b)`
 * without a scale defaults to 0 and turns 189.90 into 189.
 *
 * ## Scale is internal, and that is the point
 *
 * Every amount is carried at `SCALE` (2) because every money column in the
 * schema is `decimal(n,2)`. The two operations that need more precision
 * mid-flight — `percentageOf()` for VAT extraction and `shareOf()` for
 * proportional allocation — run their intermediate at double scale and round
 * once at the end, inside the method. A caller never chooses a scale.
 *
 * ## Not a currency-aware Money
 *
 * There is no currency on this object, deliberately. `App\Enums\Currency`
 * exists and `orders.currency`/`payments.currency` snapshot it, but the
 * catalogue is single-currency and adding a currency field here would imply
 * mixed-currency arithmetic is guarded when it is not.
 * `reference/schema/open-schema-questions.md` #2 has what real multi-currency
 * would need; when that arrives, the guard belongs here and `add()` should
 * refuse mismatched currencies.
 *
 * Immutable: every operation returns a new instance, so an amount cannot be
 * mutated out from under a caller holding it.
 */
final readonly class Money implements Stringable
{
    /** Every money column in the schema is `decimal(n,2)`. */
    public const SCALE = 2;

    /** @param numeric-string $amount */
    private function __construct(public string $amount) {}

    public static function of(string|int|float $amount): self
    {
        // Float is accepted at the boundary and immediately normalised,
        // because a factory refusing it outright would just push callers to
        // cast at the call site and lose the same precision one line earlier.
        // Anything already carrying error is beyond saving; this at least
        // stops it compounding.
        $normalised = is_float($amount)
            ? number_format($amount, self::SCALE, '.', '')
            : (string) $amount;

        if (! is_numeric($normalised)) {
            throw new InvalidArgumentException("[{$normalised}] is not a numeric amount.");
        }

        return new self(bcadd($normalised, '0', self::SCALE));
    }

    public static function zero(): self
    {
        return new self(bcadd('0', '0', self::SCALE));
    }

    public function add(self $other): self
    {
        return new self(bcadd($this->amount, $other->amount, self::SCALE));
    }

    public function subtract(self $other): self
    {
        return new self(bcsub($this->amount, $other->amount, self::SCALE));
    }

    /** Multiply by a plain quantity — a count of items, not an amount. */
    public function multiply(int|string $factor): self
    {
        $factor = (string) $factor;

        if (! is_numeric($factor)) {
            throw new InvalidArgumentException("[{$factor}] is not a numeric factor.");
        }

        return new self(bcmul($this->amount, $factor, self::SCALE));
    }

    /**
     * The portion of this amount attributable to a percentage *already
     * included in it*.
     *
     * VAT extraction, and the reason this method exists rather than callers
     * composing `multiply` and a divide: prices are stored gross (CLAUDE.md),
     * so VAT is `amount * rate / (100 + rate)`, not `amount * rate / 100`.
     * Getting that wrong overstates VAT on every line and nothing fails
     * loudly.
     *
     * The intermediate runs at double scale and rounds once at the end —
     * rounding each step compounds the error across a multi-line cart.
     * That final rounding is half-up (20% of 100.00 is 16.67, not 16.66):
     * customers reading a VAT breakdown expect ordinary commercial
     * rounding, not the truncation `bcadd($x, '0', SCALE)` alone performs.
     */
    public function percentageOf(string $rate): self
    {
        if (! is_numeric($rate)) {
            throw new InvalidArgumentException("[{$rate}] is not a numeric rate.");
        }

        $precise = bcdiv(
            bcmul($this->amount, $rate, self::SCALE * 2),
            bcadd('100', $rate, self::SCALE * 2),
            self::SCALE * 2,
        );

        return new self(self::roundHalfUp($precise));
    }

    /**
     * This amount's proportional share of `$total`, applied to `$pool`.
     *
     * Splitting a discount across the lines it matched: a line worth 30 of a
     * 100 matched subtotal takes 30% of the discount. The intermediate runs
     * at double scale for the same reason `percentageOf()` does — rounding
     * each line before summing drifts the total away from the discount that
     * was actually granted.
     *
     * Returns zero when the total is zero rather than dividing by it, which
     * is the empty-cart case rather than an error.
     *
     * Rounds half-up at the end, same as `percentageOf()` and for the same
     * reason — a customer reading a per-line discount split expects
     * ordinary commercial rounding, not truncation.
     */
    public function shareOf(self $pool, self $total): self
    {
        if ($total->isZero()) {
            return self::zero();
        }

        $precise = bcdiv(
            bcmul($pool->amount, $this->amount, self::SCALE * 2),
            $total->amount,
            self::SCALE * 2,
        );

        return new self(self::roundHalfUp($precise));
    }

    /**
     * Rounds a `SCALE * 2`-precision bcmath string to `SCALE` digits,
     * half away from zero — bcmath has no native rounding mode, and
     * `bcadd($x, '0', SCALE)` alone truncates rather than rounds.
     *
     * Nudges by half a unit at the target scale before truncating: adding
     * (or, for a negative amount, subtracting) `0.005` before cutting to 2
     * decimal places is the standard bcmath half-up technique, and works
     * identically on PHP 8.3 and 8.4 — `bcround()` is 8.4-only and this
     * project's `composer.json` still declares `^8.3`.
     *
     * @param  numeric-string  $amount
     * @return numeric-string
     */
    private static function roundHalfUp(string $amount): string
    {
        $half = bcdiv('5', bcpow('10', (string) (self::SCALE + 1)), self::SCALE + 1);

        return str_starts_with($amount, '-')
            ? bcsub($amount, $half, self::SCALE)
            : bcadd($amount, $half, self::SCALE);
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function isLessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function equals(self $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    /**
     * The amount in minor units — cents for EUR — as an integer.
     *
     * Stripe's API takes an integer number of the currency's smallest unit
     * and rejects a decimal, so this conversion has to happen somewhere. It
     * happens here because CLAUDE.md routes every money operation through
     * this class: at the call site it would be a `bcmul` with a hand-written
     * scale, which is exactly the repetition this class replaced.
     *
     * Scale 0 on the multiply truncates rather than rounds, which is safe
     * only because SCALE is 2 and the currency's minor unit is also 2 — the
     * third decimal place cannot exist on an amount this class holds. A
     * zero-decimal currency (JPY) or a three-decimal one (KWD) would both
     * need the exponent to come from the currency rather than be assumed,
     * and `App\Enums\Currency` is where that would live;
     * `schema/open-schema-questions.md` #2 tracks it. Single-currency EUR
     * today, so the assumption holds and is stated rather than hidden.
     */
    public function toMinorUnits(): int
    {
        return (int) bcmul($this->amount, '100', 0);
    }

    /**
     * The inverse of `toMinorUnits()` — Stripe (and any integer-minor-unit
     * API) reports amounts as an integer count of cents; this is where that
     * comes back into a decimal amount, rather than a caller doing
     * `bcdiv((string) $minor, '100', Money::SCALE)` by hand at the call
     * site. Same single-currency, two-decimal assumption `toMinorUnits()`
     * documents: a zero-decimal currency (JPY) or three-decimal one (KWD)
     * would need the exponent to come from the currency, not be assumed 2.
     */
    public static function fromMinorUnits(int $minor): self
    {
        return new self(bcdiv((string) $minor, '100', self::SCALE));
    }

    /**
     * How many whole percent lower `$this` is than `$original` — a discount
     * percentage for display, not a `Money` amount. Returns 0 rather than
     * dividing by zero when `$original` is zero or not actually higher than
     * `$this` (there is no discount to express as a percentage).
     *
     * Not `percentageOf()` or `shareOf()`: those extract or allocate a
     * `Money` amount from a rate or a pool. This produces a plain
     * whole-number percentage from two amounts — `ProductPrice`'s "20% off"
     * label, not a stored monetary value. The intermediate still runs at
     * double scale and rounds once at the end, for the same reason as the
     * other two: rounding earlier would drift the displayed percentage from
     * the actual prices it is computed from.
     */
    public function percentBelow(self $original): int
    {
        if (! $original->isPositive() || ! $original->isGreaterThan($this)) {
            return 0;
        }

        $saving = $original->subtract($this);

        $precise = bcdiv(
            bcmul($saving->amount, '100', self::SCALE * 2),
            $original->amount,
            self::SCALE * 2,
        );

        return (int) round((float) self::roundHalfUp($precise));
    }

    public function isZero(): bool
    {
        return $this->compareTo(self::zero()) === 0;
    }

    public function isPositive(): bool
    {
        return $this->compareTo(self::zero()) > 0;
    }

    /** Clamp to zero — a discount may not take a total negative. */
    public function atLeastZero(): self
    {
        return $this->isPositive() ? $this : self::zero();
    }

    /** The smaller of the two, for capping a discount at the line it applies to. */
    public function cappedAt(self $ceiling): self
    {
        return $this->isGreaterThan($ceiling) ? $ceiling : $this;
    }

    private function compareTo(self $other): int
    {
        return bccomp($this->amount, $other->amount, self::SCALE);
    }

    public function __toString(): string
    {
        return $this->amount;
    }
}
