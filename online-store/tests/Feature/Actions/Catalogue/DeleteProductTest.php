<?php

declare(strict_types=1);

use App\Actions\Catalogue\AddProductVariation;
use App\Actions\Catalogue\DeleteProduct;
use App\Actions\Catalogue\ForceDeleteProduct;
use App\Actions\Catalogue\UpdateProduct;
use App\Actions\Inventory\ReserveStock;
use App\Exceptions\ProductCannotBeErasedException;
use App\Exceptions\RemovedFromCatalogueException;
use App\Exceptions\VariationCannotBeErasedException;
use App\Models\CartItem;
use App\Models\Inventory;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSpecification;
use App\Models\ProductVariation;
use App\Models\WishlistItem;
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\PermissionRegistrar;

/*
 * Deleting and erasing a product. Outcome table in
 * `reference/product-write-rules.md`.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed(PermissionSeeder::class);
});

function productWithVariations(int $count = 2, int $stock = 0): Product
{
    $product = Product::factory()->create(['is_available' => true]);

    for ($i = 0; $i < $count; $i++) {
        app(AddProductVariation::class)->handle($product, variationAttributes(), $stock);
    }

    return $product;
}

it('takes the variations with the product', function (): void {
    $product = productWithVariations();

    app(DeleteProduct::class)->handle($product);

    // Cascaded in the application, not the schema — a database cascade would
    // reach inventory_movements and destroy §20's ledger.
    expect($product->fresh()->trashed())->toBeTrue()
        ->and(ProductVariation::where('product_id', $product->getKey())->count())->toBe(0)
        ->and(ProductVariation::withTrashed()->where('product_id', $product->getKey())->count())->toBe(2);
});

it('makes the variations of a deleted product unreservable', function (): void {
    $product = productWithVariations(count: 1, stock: 10);
    $variation = $product->productVariations()->sole();

    app(DeleteProduct::class)->handle($product);

    // ReserveStock checks the variation, not the product, so the cascade is
    // what makes a deleted product's stock unreachable.
    expect(fn () => app(ReserveStock::class)->handle($variation, 1))
        ->toThrow(RemovedFromCatalogueException::class);

    expect(Inventory::where('product_variation_id', $variation->getKey())->sole()->reserved_quantity)->toBe(0);
});

it('keeps the stock rows and the ledger', function (): void {
    $product = productWithVariations(count: 1, stock: 7);
    $variation = $product->productVariations()->sole();

    app(DeleteProduct::class)->handle($product);

    // Soft deletion withdraws; it does not destroy.
    $inventory = Inventory::where('product_variation_id', $variation->getKey())->sole();

    expect($inventory->current_quantity)->toBe(7)
        ->and($inventory->inventoryMovements()->count())->toBe(1);
});

it('delists a product that still has stock reserved for open orders', function (): void {
    $product = productWithVariations(count: 1, stock: 10);
    app(ReserveStock::class)->handle($product->productVariations()->sole(), 3);

    // Unlike RemoveProductVariation, reserved stock is not a refusal here:
    // delisting while orders are open is ordinary.
    app(DeleteProduct::class)->handle($product);

    expect($product->fresh()->trashed())->toBeTrue();
});

it('denies an actor without delete_product', function (): void {
    $product = productWithVariations();
    $actor = catalogueActor('update_product');

    expect(fn () => app(DeleteProduct::class)->handle($product, $actor))
        ->toThrow(AuthorizationException::class);

    expect($product->fresh()->trashed())->toBeFalse()
        ->and($product->productVariations()->count())->toBe(2);
});

it('refuses to update a product that has been deleted', function (): void {
    $product = productWithVariations();
    app(DeleteProduct::class)->handle($product);

    expect(fn () => app(UpdateProduct::class)->handle($product, ['name' => 'Renamed']))
        ->toThrow(RemovedFromCatalogueException::class);
});

it('erases a product with its variations and stock rows', function (): void {
    $product = productWithVariations();
    $variationIds = $product->productVariations()->pluck('id')->all();

    app(ForceDeleteProduct::class)->handle($product);

    // Children before parents; the reverse is error 1451.
    expect(Product::withTrashed()->whereKey($product->getKey())->exists())->toBeFalse()
        ->and(ProductVariation::withTrashed()->whereIn('id', $variationIds)->count())->toBe(0)
        ->and(Inventory::whereIn('product_variation_id', $variationIds)->count())->toBe(0);
});

it('erases the images and specifications that block the parent delete', function (): void {
    $product = productWithVariations();
    ProductImage::factory()->create(['product_id' => $product->getKey()]);
    ProductSpecification::factory()->create(['product_id' => $product->getKey()]);

    app(ForceDeleteProduct::class)->handle($product);

    // Both are NO ACTION and both raise 1451 on their own — verified against
    // the database. Without a child of each kind the erase test passes with
    // these deletes removed.
    expect(Product::withTrashed()->whereKey($product->getKey())->exists())->toBeFalse()
        ->and(ProductImage::where('product_id', $product->getKey())->count())->toBe(0)
        ->and(ProductSpecification::where('product_id', $product->getKey())->count())->toBe(0);
});

it('erases a product whose variations are already soft-deleted', function (): void {
    $product = productWithVariations();
    app(DeleteProduct::class)->handle($product);

    // A trashed variation still holds the foreign key, so querying without
    // withTrashed() would hide exactly the rows that cause 1451.
    app(ForceDeleteProduct::class)->handle($product);

    expect(Product::withTrashed()->whereKey($product->getKey())->exists())->toBeFalse()
        ->and(ProductVariation::withTrashed()->where('product_id', $product->getKey())->count())->toBe(0);
});

it('refuses to erase a product that has been ordered', function (): void {
    $product = productWithVariations();
    OrderItem::factory()->create(['product_id' => $product->getKey()]);

    // ON DELETE SET NULL, so the database would accept this and silently null
    // the reference. §19 requires the history to survive.
    expect(fn () => app(ForceDeleteProduct::class)->handle($product))
        ->toThrow(ProductCannotBeErasedException::class);

    expect(Product::withTrashed()->whereKey($product->getKey())->exists())->toBeTrue();
});

it('drops cart lines when erasing a product', function (): void {
    $product = productWithVariations();
    CartItem::factory()->create([
        'product_variation_id' => $product->productVariations()->first()->getKey(),
        'quantity' => 1,
    ]);

    app(ForceDeleteProduct::class)->handle($product);

    expect(Product::withTrashed()->whereKey($product->getKey())->exists())->toBeFalse()
        ->and(CartItem::count())->toBe(0);
});

it('refuses to erase a product that is on a wishlist', function (): void {
    $product = productWithVariations();
    WishlistItem::factory()->create(['product_id' => $product->getKey()]);

    expect(fn () => app(ForceDeleteProduct::class)->handle($product))
        ->toThrow(ProductCannotBeErasedException::class);
});

it('names the reason it refused, not just the class', function (): void {
    // All three refusals raise one class, so asserting the class alone passes
    // when the wrong branch fires. The counts and the record are what the
    // panel renders.
    $ordered = productWithVariations();
    OrderItem::factory()->count(2)->create(['product_id' => $ordered->getKey()]);

    try {
        app(ForceDeleteProduct::class)->handle($ordered);
        $this->fail('Expected the erase to be refused.');
    } catch (ProductCannotBeErasedException $e) {
        expect($e->product->is($ordered))->toBeTrue()
            ->and($e->getMessage())->toContain('2 order line(s)')
            ->and($e->getMessage())->toContain($ordered->sku);
    }

    $wishlisted = productWithVariations();
    WishlistItem::factory()->create(['product_id' => $wishlisted->getKey()]);

    try {
        app(ForceDeleteProduct::class)->handle($wishlisted);
        $this->fail('Expected the erase to be refused.');
    } catch (ProductCannotBeErasedException $e) {
        expect($e->getMessage())->toContain('wishlist');
    }
});

it('carries the record that was removed from the catalogue', function (): void {
    $product = productWithVariations();
    app(DeleteProduct::class)->handle($product);

    try {
        app(UpdateProduct::class)->handle($product, ['name' => 'Renamed']);
        $this->fail('Expected the update to be refused.');
    } catch (RemovedFromCatalogueException $e) {
        // A caller has to be able to say *which* record went without parsing
        // the message.
        expect($e->record->is($product))->toBeTrue();
    }
});

it('refuses to erase a product whose variation has stock history', function (): void {
    $product = productWithVariations(count: 1, stock: 5);

    // Delegated to ForceDeleteProductVariation, which owns the ledger refusal.
    expect(fn () => app(ForceDeleteProduct::class)->handle($product))
        ->toThrow(VariationCannotBeErasedException::class);

    expect(Product::withTrashed()->whereKey($product->getKey())->exists())->toBeTrue()
        ->and(ProductVariation::where('product_id', $product->getKey())->count())->toBe(1);
});

it('denies an actor without delete_product for an erase', function (): void {
    $product = productWithVariations();
    $actor = catalogueActor('update_product');

    expect(fn () => app(ForceDeleteProduct::class)->handle($product, $actor))
        ->toThrow(AuthorizationException::class);

    expect(Product::withTrashed()->whereKey($product->getKey())->exists())->toBeTrue();
});
