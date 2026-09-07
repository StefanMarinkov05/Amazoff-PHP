<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use App\Models\ProductVariation;
use JsonException;
use RuntimeException;

/**
 * The SKUs a transactional seeder may not consume, and the order-quantity
 * floors it must respect.
 *
 * `database/fixtures/reference/protected-skus.json` is the list; that file
 * carries the full reasoning. The short version: `TransitionOrderStatus` on
 * `=> Shipped` composes `CompleteSale`, which moves stock from reserved to
 * sold and permanently drops `current_quantity`, and `CreateOrder` reserves at
 * creation. A seeder sampling order lines at random therefore drains exactly
 * the variations `reference/schema/demo-data.md` documents as "out of stock"
 * and "exactly one left" — the states a presenter pulls up during a demo.
 *
 * The failure is silent, which is the whole reason this class exists rather
 * than a comment: every seeder run succeeds, the orders look correct, and the
 * documented state is simply gone. Nothing reports it until someone opens the
 * page mid-presentation.
 *
 * `assertSelectable()` is the guard. Call it on every line a seeder intends to
 * order, *before* building the cart — it throws rather than returning false,
 * because a seeder that silently skipped a protected line would produce a set
 * quietly smaller than the distribution it claims to have written.
 *
 * Not used by the storefront or the panel: a real customer ordering the last
 * unit of `PWR-0011` is correct behaviour, and this class has no opinion on
 * it. This is a seeding constraint only.
 */
final class ProtectedSkus
{
    private const FILE = 'database/fixtures/reference/protected-skus.json';

    /** @var list<string>|null */
    private ?array $excluded = null;

    /** @var array<string, int>|null */
    private ?array $floors = null;

    /**
     * Every SKU a seeder must exclude — variation SKUs, plus the product SKUs
     * whose variations are all excluded.
     *
     * @return list<string>
     */
    public function excluded(): array
    {
        if ($this->excluded !== null) {
            return $this->excluded;
        }

        $document = $this->document();

        /** @var list<string> $skus */
        $skus = array_merge(
            $this->skusIn($document, 'out_of_stock'),
            $this->skusIn($document, 'exactly_one_left'),
            $this->skusIn($document, 'unavailable_products'),
        );

        return $this->excluded = array_values(array_unique($skus));
    }

    /**
     * Minimum order quantity per product SKU, for the products that set one
     * above 1. These are deliberately *not* excluded — they belong in seeded
     * orders; the floor just has to be honoured or `AddToCart` refuses.
     *
     * @return array<string, int>
     */
    public function floors(): array
    {
        if ($this->floors !== null) {
            return $this->floors;
        }

        $document = $this->document();

        /** @var array<string, mixed> $section */
        $section = is_array($document['min_order_quantity'] ?? null) ? $document['min_order_quantity'] : [];

        /** @var array<string, mixed> $raw */
        $raw = is_array($section['floors'] ?? null) ? $section['floors'] : [];

        $floors = [];

        foreach ($raw as $sku => $floor) {
            if (! is_scalar($floor)) {
                throw new RuntimeException(self::FILE." has a non-scalar floor value for SKU '{$sku}'.");
            }

            $floors[(string) $sku] = (int) $floor;
        }

        return $this->floors = $floors;
    }

    /**
     * True if this variation — or the product it belongs to — is protected.
     *
     * Both are checked because the list mixes the two deliberately: an
     * unavailable product is named once rather than once per variation.
     */
    public function isProtected(ProductVariation $variation): bool
    {
        $excluded = $this->excluded();

        if (in_array($variation->sku, $excluded, true)) {
            return true;
        }

        /** @var Product|null $product */
        $product = $variation->product;

        return $product !== null && in_array($product->sku, $excluded, true);
    }

    /**
     * Guard for a seeder about to order this variation.
     *
     * Throws rather than returning a bool so a caller cannot quietly skip the
     * line and write a smaller set than its own distribution table claims.
     *
     * @throws RuntimeException
     */
    public function assertSelectable(ProductVariation $variation): void
    {
        if (! $this->isProtected($variation)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Variation %s is a protected demo SKU and must not be ordered by a seeder. '
            .'Its stock level is a documented demo state (reference/schema/demo-data.md); '
            .'reserving it destroys that state silently. Exclude it before sampling — see %s.',
            $variation->sku,
            self::FILE,
        ));
    }

    /**
     * The smallest legal quantity for this variation's product.
     */
    public function floorFor(ProductVariation $variation): int
    {
        /** @var Product|null $product */
        $product = $variation->product;

        if ($product === null) {
            return 1;
        }

        return max(1, $this->floors()[$product->sku] ?? 1);
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<string>
     */
    private function skusIn(array $document, string $section): array
    {
        /** @var array<string, mixed> $group */
        $group = is_array($document[$section] ?? null) ? $document[$section] : [];

        if (! is_array($group['skus'] ?? null)) {
            throw new RuntimeException(
                self::FILE." is missing the '{$section}.skus' list. It is load-bearing — see the file's own _comment."
            );
        }

        /** @var list<string> $skus */
        $skus = array_map(static function (mixed $sku) use ($section): string {
            if (! is_scalar($sku)) {
                throw new RuntimeException(self::FILE." has a non-scalar SKU in '{$section}.skus'.");
            }

            return (string) $sku;
        }, array_values($group['skus']));

        return $skus;
    }

    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        $path = base_path(self::FILE);

        if (! is_file($path)) {
            throw new RuntimeException(
                self::FILE.' is missing. It is not optional: without it a transactional seeder '
                .'silently consumes the demo catalogue\'s documented stock states.'
            );
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(self::FILE.' is not valid JSON: '.$e->getMessage(), previous: $e);
        }

        return $decoded;
    }
}
