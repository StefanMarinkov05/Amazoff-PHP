<?php

declare(strict_types=1);

use App\Actions\Catalogue\AddProductVariation;
use App\Actions\Catalogue\ForceDeleteProductVariation;
use App\Actions\Catalogue\RemoveProductVariation;
use App\Actions\Inventory\ReserveStock;
use App\Exceptions\ProductRequiresVariationException;
use App\Exceptions\VariationCannotBeErasedException;
use App\Exceptions\VariationHasReservedStockException;
use App\Models\CartItem;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariation;
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\PermissionRegistrar;

/*
 * Erasing a variation permanently.
 *
 * `inventories.product_variation_id` is a NO ACTION foreign key and every
 * variation created through AddProductVariation has a stock row, so deleting
 * the variation first is always error 1451 — which is exactly what Filament's
 * default ForceDeleteAction does today. Verified against the running database:
 *
 *   ERROR 1451 (23000): Cannot delete or update a parent row: a foreign key
 *   constraint fails (`inventories`, CONSTRAINT
 *   `inventories_product_variation_id_foreign` ...)
 *
 * catalogueActor() and variationAttributes() come from
 * AddProductVariationTest.php.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed(PermissionSeeder::class);
});

/** Two variations so the §6–7 invariant is never the reason a test fails. */
function erasableVariation(bool $available = false, int $stock = 0): ProductVariation
{
    $product = Product::factory()->create(['is_available' => $available]);
    app(AddProductVariation::class)->handle($product, variationAttributes());

    return app(AddProductVariation::class)->handle($product, variationAttributes(), $stock);
}

it('erases the variation and its stock row in the order the schema allows', function (): void {
    $variation = erasableVariation();
    $inventoryId = $variation->inventory()->sole()->getKey();

    app(ForceDeleteProductVariation::class)->handle($variation);

    // The child goes first. The reverse order is error 1451, which is the
    // whole reason this Action exists rather than a plain forceDelete().
    expect(ProductVariation::withTrashed()->whereKey($variation->getKey())->exists())->toBeFalse()
        ->and(Inventory::whereKey($inventoryId)->exists())->toBeFalse();
});

it('erases a variation that was already soft-deleted', function (): void {
    $variation = erasableVariation();
    app(RemoveProductVariation::class)->handle($variation);

    // The common path: remove, then empty the trash. Counting live variations
    // and comparing to one would wrongly refuse this, because a trashed
    // variation is not in that count to begin with.
    app(ForceDeleteProductVariation::class)->handle($variation);

    expect(ProductVariation::withTrashed()->whereKey($variation->getKey())->exists())->toBeFalse();
});

it('refuses a variation with stock history', function (): void {
    $variation = erasableVariation(stock: 5);

    // §20's ledger is the only record of how a quantity got where it is.
    expect(fn () => app(ForceDeleteProductVariation::class)->handle($variation))
        ->toThrow(VariationCannotBeErasedException::class);

    expect(ProductVariation::withTrashed()->whereKey($variation->getKey())->exists())->toBeTrue();
});

it('refuses a variation with stock reserved against it', function (): void {
    $variation = erasableVariation(stock: 10);
    app(ReserveStock::class)->handle($variation, 2);

    expect(fn () => app(ForceDeleteProductVariation::class)->handle($variation))
        ->toThrow(VariationHasReservedStockException::class);
});

it('drops the cart lines rather than refusing', function (): void {
    $variation = erasableVariation();
    CartItem::factory()->create(['product_variation_id' => $variation->getKey(), 'quantity' => 1]);

    // A cart is transient state, not referential integrity. Refusing would let
    // a customer pin a variation indefinitely by leaving a tab open, and the
    // line was going to die at checkout anyway. cart_items is NO ACTION, so
    // the row must go before the variation regardless.
    app(ForceDeleteProductVariation::class)->handle($variation);

    expect(ProductVariation::withTrashed()->whereKey($variation->getKey())->exists())->toBeFalse()
        ->and(CartItem::where('product_variation_id', $variation->getKey())->count())->toBe(0);
});

it('still refuses a variation whose cart reached checkout', function (): void {
    $variation = erasableVariation(stock: 10);
    CartItem::factory()->create(['product_variation_id' => $variation->getKey(), 'quantity' => 1]);
    app(ReserveStock::class)->handle($variation, 1);

    // The case dropping the cart check might look like it opened: a cart that
    // got as far as checkout holds a reservation, and reserved stock is still
    // a refusal.
    expect(fn () => app(ForceDeleteProductVariation::class)->handle($variation))
        ->toThrow(VariationHasReservedStockException::class);

    expect(CartItem::where('product_variation_id', $variation->getKey())->count())->toBe(1);
});

it('still refuses the last variation of a soft-deleted but available product', function (): void {
    $product = Product::factory()->create(['is_available' => true]);
    $variation = app(AddProductVariation::class)->handle($product, variationAttributes());
    $product->delete();

    // The guard keys off the caller's intent, not the product's trashed flag.
    // A soft-deleted product can be restored, so erasing its only variation
    // would leave a restorable product with nothing to sell. Only
    // ForceDeleteProduct waives this, because there the product is going too.
    expect(fn () => app(ForceDeleteProductVariation::class)->handle($variation))
        ->toThrow(ProductRequiresVariationException::class);
});

it('waives the last-variation rule when the product is being erased too', function (): void {
    $product = Product::factory()->create(['is_available' => true]);
    $variation = app(AddProductVariation::class)->handle($product, variationAttributes());

    app(ForceDeleteProductVariation::class)->handle($variation, null, productIsBeingErased: true);

    expect(ProductVariation::withTrashed()->whereKey($variation->getKey())->exists())->toBeFalse();
});

it('refuses the last live variation of an available product', function (): void {
    $product = Product::factory()->create(['is_available' => true]);
    $variation = app(AddProductVariation::class)->handle($product, variationAttributes());

    expect(fn () => app(ForceDeleteProductVariation::class)->handle($variation))
        ->toThrow(ProductRequiresVariationException::class);
});

it('allows erasing the last variation of an unavailable product', function (): void {
    $product = Product::factory()->create(['is_available' => false]);
    $variation = app(AddProductVariation::class)->handle($product, variationAttributes());

    app(ForceDeleteProductVariation::class)->handle($variation);

    expect($product->fresh()->productVariations()->count())->toBe(0);
});

it('denies an actor without delete_product_variation', function (): void {
    $variation = erasableVariation();
    $actor = catalogueActor('update_product_variation');

    expect(fn () => app(ForceDeleteProductVariation::class)->handle($variation, $actor))
        ->toThrow(AuthorizationException::class);

    expect(ProductVariation::withTrashed()->whereKey($variation->getKey())->exists())->toBeTrue();
});

it('allows an actor holding delete_product_variation', function (): void {
    $variation = erasableVariation();
    $actor = catalogueActor('delete_product_variation');

    app(ForceDeleteProductVariation::class)->handle($variation, $actor);

    expect(ProductVariation::withTrashed()->whereKey($variation->getKey())->exists())->toBeFalse();
});
