<?php

declare(strict_types=1);

use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidCartQuantityException;
use App\Filament\Concerns\ReportsDomainFailures;
use App\Models\Product;
use App\Models\ProductVariation;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\QueryException;

/*
 * Every domain exception in `App\Exceptions` extends either `RuntimeException`
 * (seven of eight) or `InvalidArgumentException` (`InvalidCartQuantityException`,
 * deliberately — see its own docblock). The trait has to catch both base
 * classes, or the one exception on the `InvalidArgumentException` side reaches
 * a Filament page as an uncaught exception instead of a notification — the
 * exact failure mode this trait exists to prevent for every other one.
 *
 * A standalone anonymous class rather than a real Resource page: the trait is
 * the unit under test, not any one resource's wiring, and Cart has no
 * Filament caller yet for a real integration test to go through.
 */

function reporter(): object
{
    return new class
    {
        use ReportsDomainFailures;

        public function run(callable $operation, string $title = 'Failed'): mixed
        {
            return $this->reportingDomainFailures($operation, $title);
        }
    };
}

it('catches a RuntimeException-based domain exception', function (): void {
    $variation = ProductVariation::factory()->make(['sku' => 'SKU-1']);

    expect(fn () => reporter()->run(function () use ($variation): void {
        throw new InsufficientStockException($variation, 5, 2);
    }))->toThrow(Halt::class);
});

it('catches an InvalidArgumentException-based domain exception', function (): void {
    $product = Product::factory()->make();

    // The regression case: before this fix, only RuntimeException was
    // caught, so this one — deliberately built on InvalidArgumentException —
    // would have reached the caller uncaught instead of becoming a Halt.
    expect(fn () => reporter()->run(function () use ($product): void {
        throw InvalidCartQuantityException::notPositive($product, 0);
    }))->toThrow(Halt::class);
});

it('does not catch a non-domain RuntimeException', function (): void {
    expect(fn () => reporter()->run(function (): void {
        throw new RuntimeException('not a domain exception');
    }))->toThrow(RuntimeException::class, 'not a domain exception');
});

it('does not catch a non-domain InvalidArgumentException', function (): void {
    expect(fn () => reporter()->run(function (): void {
        throw new InvalidArgumentException('not a domain exception');
    }))->toThrow(InvalidArgumentException::class, 'not a domain exception');
});

it('does not catch a QueryException', function (): void {
    // A defect, not a refusal — swallowing this into a toast would hide
    // exactly the failure that should be loud, per the trait's own docblock.
    expect(fn () => reporter()->run(function (): void {
        throw new QueryException('mysql', 'select 1', [], new Exception('gone away'));
    }))->toThrow(QueryException::class);
});
