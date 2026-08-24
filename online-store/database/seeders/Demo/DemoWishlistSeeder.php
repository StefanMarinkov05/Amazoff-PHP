<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Product;
use App\Models\User;
use App\Models\WishlistItem;
use Illuminate\Database\Seeder;

/**
 * Wishlists for a slice of the demo customer base. Same reasoning as
 * `DemoCartSeeder`: state, not content, so a seeder produces it rather than
 * a fixture. Must run after `DemoCustomerSeeder` and the catalogue.
 *
 * `UNIQUE(user_id, product_id)` is the only invariant here, and the DB
 * enforces it directly — no Action, no `firstOrCreate` needed as long as
 * each customer's products are drawn without repeats, which `random()`
 * on a collection already guarantees.
 */
class DemoWishlistSeeder extends Seeder
{
    /** Fraction of demo customers who get a wishlist. */
    private const CUSTOMER_SHARE = 0.20;

    private const ITEMS_MIN = 1;

    private const ITEMS_MAX = 5;

    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $products = Product::query()->get();

        if ($products->isEmpty()) {
            $this->command?->warn('No products to build demo wishlists from — seed the catalogue first.');

            return;
        }

        $customers = User::query()->inRandomOrder()->get();
        $sample = $customers->random((int) round($customers->count() * self::CUSTOMER_SHARE));

        foreach ($sample as $customer) {
            $count = min($products->count(), random_int(self::ITEMS_MIN, self::ITEMS_MAX));

            foreach ($products->random($count) as $product) {
                WishlistItem::query()->create([
                    'user_id' => $customer->getKey(),
                    'product_id' => $product->getKey(),
                ]);
            }
        }
    }
}
