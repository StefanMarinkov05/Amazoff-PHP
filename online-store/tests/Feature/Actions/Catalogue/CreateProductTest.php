<?php

declare(strict_types=1);

use App\Actions\Catalogue\CreateProduct;
use App\Actions\Catalogue\UpdateProduct;
use App\Enums\InventoryMovementType;
use App\Exceptions\ProductRequiresVariationException;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariation;
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Spatie\Permission\PermissionRegistrar;

/*
 * §6–7 — every sellable product has at least one variation, because stock
 * hangs off the variation rather than the product. Three tables in one
 * operation, which is ADR-0007's threshold for an Action.
 *
 * catalogueActor() and variationAttributes() are defined in
 * AddProductVariationTest.php; Pest loads test files into the global scope, so
 * they are shared rather than duplicated.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed(PermissionSeeder::class);
});

/** @return array<string, mixed> */
function productAttributes(array $overrides = []): array
{
    return array_merge([
        'product_category_id' => ProductCategory::factory()->create()->getKey(),
        'name' => 'Test product',
        'slug' => fake()->unique()->slug(3),
        'sku' => fake()->unique()->regexify('[A-Z0-9]{16}'),
        'regular_price' => '189.90',
        'vat_rate' => '20.00',
        'min_order_quantity' => 1,
        'is_available' => true,
    ], $overrides);
}

it('creates a product with its variation and stock row', function (): void {
    $product = app(CreateProduct::class)->handle(productAttributes(), [variationAttributes()]);

    expect($product->exists)->toBeTrue()
        ->and($product->productVariations()->count())->toBe(1)
        ->and(Inventory::count())->toBe(1);
});

it('gives every variation its own stock row', function (): void {
    $product = app(CreateProduct::class)->handle(productAttributes(), [
        variationAttributes(),
        variationAttributes(),
        variationAttributes(),
    ]);

    // UNIQUE(product_variation_id) means one row each; three variations
    // sharing one stock row would be three products sharing one quantity.
    expect($product->productVariations()->count())->toBe(3)
        ->and(Inventory::count())->toBe(3)
        ->and(Inventory::distinct()->count('product_variation_id'))->toBe(3);
});

it('refuses a product with no variation and writes nothing', function (): void {
    expect(fn () => app(CreateProduct::class)->handle(productAttributes(), []))
        ->toThrow(ProductRequiresVariationException::class);

    // The invariant is the reason this is an Action. A product row saved
    // before the refusal would be exactly the state it exists to prevent.
    expect(Product::count())->toBe(0);
});

it('passes opening stock through to the ledger', function (): void {
    $product = app(CreateProduct::class)->handle(productAttributes(), [
        variationAttributes(['initial_quantity' => 12]),
    ]);

    $inventory = $product->productVariations()->sole()->inventory()->sole();

    expect($inventory->current_quantity)->toBe(12)
        ->and($inventory->inventoryMovements()->sole()->movement_type)
        ->toBe(InventoryMovementType::InitialStock);
});

it('does not persist initial_quantity as a variation column', function (): void {
    $product = app(CreateProduct::class)->handle(productAttributes(), [
        variationAttributes(['initial_quantity' => 5]),
    ]);

    // Eloquent discards a non-fillable key silently (troubleshooting.md, "A
    // seeded column silently does nothing"), so the only evidence that it was
    // stripped deliberately is that the quantity landed on the stock row.
    $variation = $product->productVariations()->sole();

    expect($variation->getAttributes())->not->toHaveKey('initial_quantity')
        ->and($variation->inventory()->sole()->current_quantity)->toBe(5);
});

it('rolls the whole product back when one variation fails', function (): void {
    $taken = fake()->unique()->regexify('[A-Z0-9]{16}');
    ProductVariation::factory()->create(['sku' => $taken]);

    // Counted rather than assumed to be zero: ProductVariation::factory()
    // brings its own Product, and a ProductImage that brings another.
    $before = [Product::count(), ProductVariation::count(), Inventory::count()];

    expect(fn () => app(CreateProduct::class)->handle(productAttributes(), [
        variationAttributes(),
        variationAttributes(['sku' => $taken]),
    ]))->toThrow(QueryException::class);

    // Nothing from this call survives: not the product, not the first
    // variation, not its stock row. This is also the proof that ADR-0007's
    // savepoint composition works — AddProductVariation opened its own
    // transaction per variation and committed the first one, and the
    // outermost boundary still took it back.
    expect([Product::count(), ProductVariation::count(), Inventory::count()])
        ->toBe($before);
});

it('denies an actor without create_product', function (): void {
    // Holds create_product_variation deliberately: an actor with neither is
    // denied by AddProductVariation's gate instead, which would pass this
    // test with CreateProduct's own gate deleted.
    $actor = catalogueActor('create_product_variation');

    expect(fn () => app(CreateProduct::class)->handle(productAttributes(), [variationAttributes()], $actor))
        ->toThrow(AuthorizationException::class);

    expect(Product::count())->toBe(0);
});

it('denies an actor holding create_product but not create_product_variation', function (): void {
    $actor = catalogueActor('create_product');

    // The actor is passed down rather than dropped, so the variation's own
    // policy still applies. Dropping it here is the failure ADR-0007 names:
    // a caller passing null and silently skipping a check.
    expect(fn () => app(CreateProduct::class)->handle(productAttributes(), [variationAttributes()], $actor))
        ->toThrow(AuthorizationException::class);

    expect(Product::count())->toBe(0);
});

it('allows an actor holding both catalogue permissions', function (): void {
    $actor = catalogueActor('create_product', 'create_product_variation');

    $product = app(CreateProduct::class)->handle(productAttributes(), [variationAttributes()], $actor);

    expect($product->exists)->toBeTrue()
        ->and(Inventory::count())->toBe(1);
});

/*
 * UpdateProduct — the same invariant, on the one move that can break it after
 * creation. An edit cannot delete a variation, but a product can lose its last
 * one through the variations relation manager and be published afterwards.
 */

it('updates a product that has a variation', function (): void {
    $product = app(CreateProduct::class)->handle(productAttributes(), [variationAttributes()]);

    $updated = app(UpdateProduct::class)->handle($product, ['name' => 'Renamed', 'regular_price' => '99.00']);

    expect($updated->fresh()->name)->toBe('Renamed')
        ->and($updated->fresh()->regular_price)->toBe('99.00');
});

it('leaves columns absent from the payload alone', function (): void {
    $product = app(CreateProduct::class)->handle(
        productAttributes(['name' => 'Original', 'regular_price' => '189.90', 'is_featured' => true]),
        [variationAttributes()],
    );

    app(UpdateProduct::class)->handle($product, ['name' => 'Renamed']);

    // Partial payloads are the shape that makes concurrent edits survivable,
    // so an Action quietly widening a write would matter — see
    // ConcurrentProductEditTest.
    $fresh = $product->fresh();

    expect($fresh->name)->toBe('Renamed')
        ->and($fresh->regular_price)->toBe('189.90')
        ->and($fresh->is_featured)->toBeTrue();
});

it('ignores a key that is not a product column', function (): void {
    $product = app(CreateProduct::class)->handle(productAttributes(), [variationAttributes()]);

    app(UpdateProduct::class)->handle($product, ['name' => 'Renamed', 'not_a_column' => 'x']);

    expect($product->fresh()->name)->toBe('Renamed')
        ->and($product->fresh()->getAttributes())->not->toHaveKey('not_a_column');
});

it('refuses to make a product available with no variation', function (): void {
    $product = Product::factory()->create(['is_available' => false, 'name' => 'Unchanged']);

    expect(fn () => app(UpdateProduct::class)->handle($product, [
        'is_available' => true,
        'name' => 'Published',
    ]))->toThrow(ProductRequiresVariationException::class);

    // The rest of the edit goes with it. A partial save would leave the
    // rename applied and the flag not, which is worse than either.
    expect($product->fresh()->is_available)->toBeFalse()
        ->and($product->fresh()->name)->toBe('Unchanged');
});

it('allows editing a product that stays unavailable with no variation', function (): void {
    $product = Product::factory()->create(['is_available' => false]);

    // §6–7 words the invariant around sellability. A draft with nothing in it
    // yet is a half-entered record, not a broken one.
    $updated = app(UpdateProduct::class)->handle($product, ['name' => 'Draft']);

    expect($updated->fresh()->name)->toBe('Draft')
        ->and($updated->fresh()->is_available)->toBeFalse();
});

it('refuses to publish a product whose only variation is soft-deleted', function (): void {
    $product = app(CreateProduct::class)->handle(productAttributes(['is_available' => false]), [
        variationAttributes(),
    ]);
    $product->productVariations()->sole()->delete();

    // The count runs through the SoftDeletes global scope, so a trashed
    // variation is not a variation. Nothing can reserve stock against it.
    expect(fn () => app(UpdateProduct::class)->handle($product, ['is_available' => true]))
        ->toThrow(ProductRequiresVariationException::class);
});

it('keeps an already-available product editable while it has a variation', function (): void {
    $product = app(CreateProduct::class)->handle(productAttributes(), [variationAttributes()]);

    $updated = app(UpdateProduct::class)->handle($product, ['is_featured' => true]);

    expect($updated->fresh()->is_featured)->toBeTrue();
});

it('denies an actor without update_product', function (): void {
    $product = app(CreateProduct::class)->handle(productAttributes(['name' => 'Original']), [variationAttributes()]);
    $actor = catalogueActor('create_product');

    expect(fn () => app(UpdateProduct::class)->handle($product, ['name' => 'Renamed'], $actor))
        ->toThrow(AuthorizationException::class);

    expect($product->fresh()->name)->toBe('Original');
});

it('allows an actor holding update_product', function (): void {
    $product = app(CreateProduct::class)->handle(productAttributes(), [variationAttributes()]);
    $actor = catalogueActor('update_product');

    $updated = app(UpdateProduct::class)->handle($product, ['name' => 'Renamed'], $actor);

    expect($updated->fresh()->name)->toBe('Renamed');
});
