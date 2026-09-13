<?php

declare(strict_types=1);

use App\Support\Money;

/*
 * Money exists because CLAUDE.md forbids float for money and 30 bare `bc*`
 * calls enforced that by discipline alone. These tests are about the
 * guarantees the class adds over calling bcmath directly — scale that cannot
 * be forgotten, and the two calculations whose intermediate precision is not
 * obvious. Testing that bcadd adds is testing PHP.
 */

it('carries two decimal places whatever it is given', function (string|int|float $input, string $expected): void {
    expect((string) Money::of($input))->toBe($expected);
})->with([
    'string with cents' => ['189.90', '189.90'],
    'string without' => ['189', '189.00'],
    'integer' => [189, '189.00'],
    'more precision than the scale' => ['189.909', '189.90'],
    'zero' => ['0', '0.00'],
    'negative' => ['-5.5', '-5.50'],
]);

it('refuses a non-numeric amount rather than silently becoming zero', function (): void {
    expect(fn () => Money::of('not money'))->toThrow(InvalidArgumentException::class);
});

it('does not truncate the way a scale-less bcadd would', function (): void {
    // bcadd('189.90', '0') with no scale returns '189' — the exact bug this
    // class exists to make unreachable.
    expect((string) Money::of('189.90')->add(Money::zero()))->toBe('189.90');
});

it('adds, subtracts and multiplies at two places', function (): void {
    expect((string) Money::of('10.10')->add(Money::of('0.20')))->toBe('10.30')
        ->and((string) Money::of('10.10')->subtract(Money::of('0.20')))->toBe('9.90')
        ->and((string) Money::of('19.99')->multiply(3))->toBe('59.97');
});

it('keeps a cent that float arithmetic loses', function (): void {
    // 0.1 + 0.2 !== 0.3 in binary floating point.
    expect((string) Money::of('0.10')->add(Money::of('0.20')))->toBe('0.30');
});

describe('percentageOf — VAT extraction from a gross price', function (): void {
    it('extracts the portion already included, not a percentage on top', function (): void {
        // 20% VAT inside 120.00 is 20.00, not 24.00. Getting this backwards
        // overstates VAT on every line and nothing fails loudly.
        expect((string) Money::of('120.00')->percentageOf('20.00'))->toBe('20.00');
    });

    it('rounds once at the end rather than at each step', function (): void {
        // 19.99 * 20 / 120 = 3.331666..., which rounds to 3.33. A
        // scale-2 intermediate would have produced 3.34 here.
        expect((string) Money::of('19.99')->percentageOf('20.00'))->toBe('3.33');
    });

    it('returns zero for a zero rate', function (): void {
        expect((string) Money::of('120.00')->percentageOf('0.00'))->toBe('0.00');
    });

    it('rounds half-up, not down, at the final digit', function (): void {
        // 20% of 100.00 gross is 100 * 20 / 120 = 16.6666..., which rounds
        // half-up to 16.67. A truncating final step (bare bcadd(x, '0', 2))
        // would have produced 16.66, silently short-changing VAT extraction
        // by a cent on every such line.
        expect((string) Money::of('100.00')->percentageOf('20.00'))->toBe('16.67');
    });
});

describe('shareOf — proportional allocation of a discount', function (): void {
    it('gives a line its proportional share', function (): void {
        // A 30.00 line out of a 100.00 matched subtotal takes 30% of a
        // 10.00 discount.
        $share = Money::of('30.00')->shareOf(Money::of('10.00'), Money::of('100.00'));

        expect((string) $share)->toBe('3.00');
    });

    it('returns zero rather than dividing by a zero total', function (): void {
        $share = Money::of('30.00')->shareOf(Money::of('10.00'), Money::zero());

        expect((string) $share)->toBe('0.00');
    });

    it('rounds half-up, not down, at the final digit', function (): void {
        // 1.00 out of an 8.00 matched subtotal takes 1/8 = 0.125 of a 1.00
        // discount, which rounds half-up to 0.13 rather than truncating to
        // 0.12.
        $share = Money::of('1.00')->shareOf(Money::of('1.00'), Money::of('8.00'));

        expect((string) $share)->toBe('0.13');
    });

    it('does not drift the sum away from the discount granted', function (): void {
        // Three equal lines splitting 10.00 three ways. Each is 3.33 and the
        // sum is 9.99 — one cent unallocated, which is inherent to the split
        // and must not be silently inflated to 10.00 by rounding up.
        $total = Money::of('90.00');
        $pool = Money::of('10.00');

        $sum = Money::zero();

        foreach (['30.00', '30.00', '30.00'] as $line) {
            $sum = $sum->add(Money::of($line)->shareOf($pool, $total));
        }

        expect((string) $sum)->toBe('9.99');
    });
});

describe('fromMinorUnits — Stripe cents back to a decimal amount', function (): void {
    it('is the inverse of toMinorUnits', function (): void {
        expect((string) Money::fromMinorUnits(19990))->toBe('199.90')
            ->and(Money::of('199.90')->toMinorUnits())->toBe(19990);
    });

    it('handles zero and single-digit cent amounts', function (): void {
        expect((string) Money::fromMinorUnits(0))->toBe('0.00')
            ->and((string) Money::fromMinorUnits(5))->toBe('0.05');
    });
});

describe('percentBelow — a discount percentage for display, not a stored amount', function (): void {
    it('computes the whole-number percent one amount is below another', function (string $original, string $reduced, int $expected): void {
        expect(Money::of($reduced)->percentBelow(Money::of($original)))->toBe($expected);
    })->with([
        '20% off' => ['100.00', '80.00', 20],
        'non-round prices' => ['19.99', '14.99', 25],
        'a two-thirds discount' => ['99.99', '33.33', 67],
        'a one-third discount' => ['100.00', '66.67', 33],
    ]);

    it('returns zero rather than dividing by zero when the original is zero', function (): void {
        expect(Money::of('0.00')->percentBelow(Money::of('0.00')))->toBe(0);
    });

    it('returns zero when there is no actual discount', function (): void {
        expect(Money::of('100.00')->percentBelow(Money::of('100.00')))->toBe(0)
            ->and(Money::of('120.00')->percentBelow(Money::of('100.00')))->toBe(0);
    });

    it('returns zero for a difference too small to round to a whole percent', function (): void {
        // 9.99 is 0.1% below 10.00 — rounds to 0, not a phantom "1% off".
        expect(Money::of('9.99')->percentBelow(Money::of('10.00')))->toBe(0);
    });
});

it('compares without float error', function (): void {
    expect(Money::of('10.00')->isGreaterThan(Money::of('9.99')))->toBeTrue()
        ->and(Money::of('9.99')->isLessThan(Money::of('10.00')))->toBeTrue()
        ->and(Money::of('10.00')->equals(Money::of('10.000')))->toBeTrue()
        ->and(Money::of('0.00')->isZero())->toBeTrue()
        ->and(Money::of('0.01')->isPositive())->toBeTrue()
        ->and(Money::of('-0.01')->isPositive())->toBeFalse();
});

it('clamps a negative to zero, for a discount that would go past the total', function (): void {
    expect((string) Money::of('-5.00')->atLeastZero())->toBe('0.00')
        ->and((string) Money::of('5.00')->atLeastZero())->toBe('5.00');
});

it('caps at a ceiling, for a discount larger than the line it applies to', function (): void {
    expect((string) Money::of('50.00')->cappedAt(Money::of('30.00')))->toBe('30.00')
        ->and((string) Money::of('20.00')->cappedAt(Money::of('30.00')))->toBe('20.00');
});

it('is immutable — an operation never mutates the amount it was called on', function (): void {
    $original = Money::of('10.00');
    $original->add(Money::of('5.00'));

    expect((string) $original)->toBe('10.00');
});
