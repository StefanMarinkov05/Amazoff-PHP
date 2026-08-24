<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\CouponScope;
use App\Enums\CouponType;
use App\Models\Coupon;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * One coupon per state `fixture-format.md` requires the demo set to cover,
 * not a count to pad — `CouponFactory` supplies the filler fields, this
 * seeder pins the ones that put each row in its required state. Must run
 * after the catalogue: the `scope: products` row targets real product rows.
 *
 * `times_used = total_usage_limit` on the last row is a direct write, not a
 * redemption run through `RedeemCoupon` — no Action enforces that cap yet,
 * per the same row in `reference/actions.md`'s exception table this seeder's
 * docblock is not the place to relitigate.
 */
class DemoCouponSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        Coupon::factory()->create([
            'code' => 'WELCOME10',
            'type' => CouponType::Percentage,
            'scope' => CouponScope::EntireOrder,
            'value' => '10.00',
            'starts_at' => null,
            'ends_at' => null,
            'is_active' => true,
        ]);

        Coupon::factory()->create([
            'code' => 'SUMMER20',
            'type' => CouponType::Percentage,
            'scope' => CouponScope::EntireOrder,
            'value' => '20.00',
            'starts_at' => Carbon::now()->subDays(60),
            'ends_at' => Carbon::now()->subDays(30),
            'is_active' => true,
        ]);

        Coupon::factory()->create([
            'code' => 'WINTER25',
            'type' => CouponType::Percentage,
            'scope' => CouponScope::EntireOrder,
            'value' => '25.00',
            'starts_at' => Carbon::now()->addDays(30),
            'ends_at' => Carbon::now()->addDays(60),
            'is_active' => true,
        ]);

        Coupon::factory()->create([
            'code' => 'FLAT15',
            'type' => CouponType::Fixed,
            'scope' => CouponScope::EntireOrder,
            'value' => '15.00',
            'starts_at' => null,
            'ends_at' => null,
            'is_active' => true,
        ]);

        /** @var Coupon $productCoupon */
        $productCoupon = Coupon::factory()->create([
            'code' => 'TOOLDEAL',
            'type' => CouponType::Percentage,
            'scope' => CouponScope::Products,
            'value' => '15.00',
            'starts_at' => null,
            'ends_at' => null,
            'is_active' => true,
        ]);

        $targets = Product::query()->inRandomOrder()->limit(3)->pluck('id');

        if ($targets->isNotEmpty()) {
            $productCoupon->products()->sync($targets);
        }

        // NOT "at its cap" — `coupons.times_used` does not exist
        // (`reference/actions.md`), `RedeemCoupon` counts real
        // `coupon_redemptions` rows instead, and a redemption requires a
        // real `order_id` (NOT NULL, cascade-on-delete). Orders are 0-scope
        // for this seed (`fixture-format.md`'s record-counts table), so
        // faking one just to exhaust this coupon would mean fabricating
        // order rows nothing else in the demo set produces. This row is
        // the boundary case only — `total_usage_limit: 1`, never redeemed —
        // proving the narrow-limit path exists; "reached" needs a real
        // order and belongs to whichever session seeds orders.
        Coupon::factory()->create([
            'code' => 'ONEUSEONLY',
            'type' => CouponType::Fixed,
            'scope' => CouponScope::EntireOrder,
            'value' => '10.00',
            'starts_at' => null,
            'ends_at' => null,
            'total_usage_limit' => 1,
            'is_active' => true,
        ]);
    }
}
