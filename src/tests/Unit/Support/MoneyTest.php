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
