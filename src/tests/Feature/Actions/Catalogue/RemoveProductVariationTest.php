<?php

declare(strict_types=1);

use App\Actions\Catalogue\AddProductVariation;
use App\Actions\Catalogue\RemoveProductVariation;
use App\Actions\Inventory\ReleaseStock;
use App\Actions\Inventory\ReserveStock;
use App\Exceptions\ProductRequiresVariationException;
use App\Exceptions\VariationHasReservedStockException;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariation;
use Database\Seeders\System\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\PermissionRegistrar;

/*
 * The third door on §6–7's invariant. `CreateProduct` refuses a product with
 * no variation and `UpdateProduct` refuses to publish one, which left removal
 * as the way through: deleting the only variation of an available product
 * needed no concurrency and nothing objected.
 *
 * catalogueActor() and variationAttributes() come from
 * AddProductVariationTest.php — Pest loads test files into the global scope.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed(PermissionSeeder::class);
});

/** @return array{0: Product, 1: ProductVariation} */
function productWithOneVariation(bool $available, int $stock = 0): array
{
    $product = Product::factory()->create(['is_available' => $available]);
    $variation = app(AddProductVariation::class)->handle($product, variationAttributes(), $stock, null);

    return [$product, $variation];
}

it('removes a variation the product can spare', function (): void {
    [$product] = productWithOneVariation(available: true);
    $spare = app(AddProductVariation::class)->handle($product, variationAttributes(), 0, null);

    app(RemoveProductVariation::class)->handle($spare, null);

    expect($product->fresh()->productVariations()->count())->toBe(1)
        ->and($spare->fresh()->trashed())->toBeTrue();
});

it('refuses to remove the last variation of an available product', function (): void {
    [$product, $variation] = productWithOneVariation(available: true);

    expect(fn () => app(RemoveProductVariation::class)->handle($variation, null))
        ->toThrow(ProductRequiresVariationException::class);

    expect($variation->fresh()->trashed())->toBeFalse()
        ->and($product->fresh()->productVariations()->count())->toBe(1);
});

it('allows removing the last variation of an unavailable product', function (): void {
    [$product, $variation] = productWithOneVariation(available: false);

    // §6–7 words the invariant around sellability. A draft with nothing in it
    // is a half-entered record, and UpdateProduct will refuse to publish it.
    app(RemoveProductVariation::class)->handle($variation, null);

    expect($variation->fresh()->trashed())->toBeTrue()
        ->and($product->fresh()->productVariations()->count())->toBe(0);
});

it('counts trashed siblings as gone when deciding', function (): void {
    [$product, $first] = productWithOneVariation(available: false);
    $second = app(AddProductVariation::class)->handle($product, variationAttributes(), 0, null);

    app(RemoveProductVariation::class)->handle($first, null);
    $product->update(['is_available' => true]);

    // One live variation left, so this is the last one — the trashed sibling
    // must not make it look otherwise.
    expect(fn () => app(RemoveProductVariation::class)->handle($second, null))
        ->toThrow(ProductRequiresVariationException::class);
});

it('refuses to remove a variation with stock reserved against it', function (): void {
    [$product, $variation] = productWithOneVariation(available: false, stock: 10);
    app(AddProductVariation::class)->handle($product, variationAttributes(), 0, null);
    app(ReserveStock::class)->handle($variation, 3, null);

    // Removing it would strand the reservation: still subtracted from
    // available(), on a row nothing lists any more.
    expect(fn () => app(RemoveProductVariation::class)->handle($variation, null))
        ->toThrow(VariationHasReservedStockException::class);

    expect($variation->fresh()->trashed())->toBeFalse();
});

it('allows removal once the reservation is released', function (): void {
    [$product, $variation] = productWithOneVariation(available: false, stock: 10);
    app(AddProductVariation::class)->handle($product, variationAttributes(), 0, null);
    app(ReserveStock::class)->handle($variation, 3, null);
    app(ReleaseStock::class)->handle($variation, 3, null);

    app(RemoveProductVariation::class)->handle($variation, null);

    expect($variation->fresh()->trashed())->toBeTrue();
});

it('leaves the inventory row and its ledger behind', function (): void {
    [$product, $variation] = productWithOneVariation(available: false, stock: 7);
    app(AddProductVariation::class)->handle($product, variationAttributes(), 0, null);

    app(RemoveProductVariation::class)->handle($variation, null);

    // Soft-deleting the variation is not deleting its history. §20's ledger is
    // what makes a past quantity explainable, and a restored variation finds
    // its stock where it left it.
    $inventory = Inventory::where('product_variation_id', $variation->getKey())->sole();

    expect($inventory->current_quantity)->toBe(7)
        ->and($inventory->inventoryMovements()->count())->toBe(1);
});

it('denies an actor without delete_product_variation', function (): void {
    [, $variation] = productWithOneVariation(available: false);
    $actor = catalogueActor('update_product_variation');

    expect(fn () => app(RemoveProductVariation::class)->handle($variation, $actor))
        ->toThrow(AuthorizationException::class);

    expect($variation->fresh()->trashed())->toBeFalse();
});

it('allows an actor holding delete_product_variation', function (): void {
    [, $variation] = productWithOneVariation(available: false);
    $actor = catalogueActor('delete_product_variation');

    app(RemoveProductVariation::class)->handle($variation, $actor);

    expect($variation->fresh()->trashed())->toBeTrue();
});

it('skips the policy for a null actor', function (): void {
    [, $variation] = productWithOneVariation(available: false);

    app(RemoveProductVariation::class)->handle($variation, null);

    expect($variation->fresh()->trashed())->toBeTrue();
});
