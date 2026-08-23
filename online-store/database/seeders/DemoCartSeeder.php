<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Cart\TouchCartExpiry;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Carts for a slice of the demo customer base — not every customer, since a
 * cart on every account is unrealistic (`fixture-format.md`'s record-counts
 * table). Must run after `DemoCustomerSeeder` and the catalogue: both are
 * read here, not created.
 *
 * `expires_at` is set through `TouchCartExpiry`, the same Action every real
 * cart write goes through, rather than a literal date — a registered
 * customer's cart is always `null` (never expires) and a guest cart is
 * `now() + guest_ttl_hours`. Writing either by hand would drift from
 * `TouchCartExpiry`'s policy the first time `config('cart.guest_ttl_hours')`
 * changes.
 *
 * `CalculateCartTotals::forCart()` is what a page reads to show a total —
 * nothing here writes one, because nothing on `carts` stores it.
 */
class DemoCartSeeder extends Seeder
{
    /** Fraction of demo customers who get a cart. */
    private const CUSTOMER_SHARE = 0.35;

    /** Guest (no `user_id`) carts, for state coverage the customer share cannot produce. */
    private const GUEST_CART_COUNT = 8;

    private const ITEMS_PER_CART_MIN = 1;

    private const ITEMS_PER_CART_MAX = 4;

    public function __construct(private readonly TouchCartExpiry $touchExpiry) {}

    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        /** @var Collection<int, ProductVariation> $variations */
        $variations = ProductVariation::query()->with('product')->get();

        if ($variations->isEmpty()) {
            $this->command?->warn('No product variations to build demo carts from — seed the catalogue first.');

            return;
        }

        $customers = User::query()->inRandomOrder()->get();
        $sample = $customers->random((int) round($customers->count() * self::CUSTOMER_SHARE));

        foreach ($sample as $customer) {
            $cart = Cart::query()->create(['user_id' => $customer->getKey()]);
            $this->fillCart($cart, $variations);
            $this->touchExpiry->handle($cart);
        }

        for ($i = 0; $i < self::GUEST_CART_COUNT; $i++) {
            $cart = Cart::query()->create(['session_id' => (string) Str::uuid()]);
            $this->fillCart($cart, $variations);
            $this->touchExpiry->handle($cart);
        }
    }

    /**
     * @param  Collection<int, ProductVariation>  $variations
     */
    private function fillCart(Cart $cart, Collection $variations): void
    {
        $count = min($variations->count(), random_int(self::ITEMS_PER_CART_MIN, self::ITEMS_PER_CART_MAX));

        // UNIQUE(cart_id, product_variation_id): one line per variation, so
        // the lines for a single cart must not repeat one.
        foreach ($variations->random($count) as $variation) {
            /** @var Product|null $product */
            $product = $variation->product;

            if ($product === null || ! $product->is_available || ! $variation->is_available) {
                continue;
            }

            CartItem::query()->create([
                'cart_id' => $cart->getKey(),
                'product_variation_id' => $variation->getKey(),
                'quantity' => random_int(1, 3),
            ]);
        }
    }
}
