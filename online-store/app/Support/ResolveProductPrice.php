<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use App\Support\ProductPrice;
use Illuminate\Support\Carbon;

/** This class answers the question - what price do I show for this product,
** before the customer has picked a variation?
*/


final class ResolveProductPrice
{
    public static function current(Product $product): ProductPrice
    {
        $currentPrice = self::getCurrentProductPrice($product);
        $regularPrice = self::getRegularProductPrice($product);
        $onSale = self::discountIsActive($product);
        $discountPercent = self::discountPercent($product);
        return new ProductPrice(
            current: $currentPrice,
            regular: $regularPrice,
            onSale: $onSale,
            percent: $discountPercent
        );
    }

    public static function getCurrentProductPrice(Product $product): string
    {
        return self::discountIsActive($product)
            ? (string) $product->discount_price
            : (string) $product->regular_price;
    }

    public static function getRegularProductPrice(Product $product): string
    {
        return (string) $product->regular_price;
    }

    public static function discountIsActive(Product $product): bool
    {
        if ($product->discount_price === null) {
            return false;
        }

        return self::windowActive($product);
    }

    public static function discountPercent(Product $product): int
    {
        $regular  = (string) $product->regular_price;
        $discount = (string) $product->discount_price;

        if (bccomp($regular, '0.00', 2) <= 0 || ! self::discountIsActive($product)) {
            return 0;
        }

        $saving = bcsub($regular, $discount, 2);

        return (int) round((float) bcmul(bcdiv($saving, $regular, 4), '100', 2));
    }

    public static function windowActive(Product $product): bool
    {
        $now = Carbon::now();

        if ($product->discount_starts_at !== null && $now->lt($product->discount_starts_at)) {
            return false;
        }

        if ($product->discount_ends_at !== null && $now->gt($product->discount_ends_at)) {
            return false;
        }

        return true;
    }
}
