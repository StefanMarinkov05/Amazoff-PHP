<?php

declare(strict_types=1);

use App\Actions\Catalogue\AddProductVariation;
use App\Enums\InventoryMovementType;
use App\Exceptions\AttributeValueNotOnProductException;
use App\Exceptions\RemovedFromCatalogueException;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\User;
use Database\Seeders\System\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\PermissionRegistrar;

/*
 * §7 puts stock on the variation and `inventories` has
 * UNIQUE(product_variation_id), so a variation is only half a record until its
 * stock row exists. Every inventory Action reads that row with firstOrFail(),
 * which means the missing half surfaces at checkout rather than at creation.
 *
 * That pairing is the whole reason ADR-0007 makes this an Action instead of
 * leaving the variations relation manager to write one table.
 */

beforeEach(function (): void {
    // RefreshDatabase truncates without clearing spatie's 24-hour permission
    // cache — see how-to/troubleshooting/database-and-migrations.md, "A
    // permission change saves and does not take effect".
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed(PermissionSeeder::class);
});

it('creates the variation and the stock row together', function (): void {
    $product = Product::factory()->create();

    $variation = app(AddProductVariation::class)->handle($product, variationAttributes(), 0, null);

    expect($variation->product_id)->toBe($product->getKey())
        ->and($variation->inventory()->exists())->toBeTrue();
});

it('starts every stock counter at zero when no opening stock is given', function (): void {
    $product = Product::factory()->create();

    $variation = app(AddProductVariation::class)->handle($product, variationAttributes(), 0, null);
    $inventory = $variation->inventory()->sole();

    expect($inventory->current_quantity)->toBe(0)
        ->and($inventory->reserved_quantity)->toBe(0)
        ->and($inventory->sold_quantity)->toBe(0)
        ->and($inventory->returned_quantity)->toBe(0)
        ->and($inventory->damaged_quantity)->toBe(0)
        ->and($inventory->available())->toBe(0);
});

it('writes no ledger row when nothing arrived', function (): void {
    $product = Product::factory()->create();

    $variation = app(AddProductVariation::class)->handle($product, variationAttributes(), 0, null);

    // A movement recording that zero units arrived says nothing, and the
    // ledger is summed.
    expect($variation->inventory()->sole()->inventoryMovements()->count())->toBe(0);
});

it('records opening stock as an initial stock movement', function (): void {
    $product = Product::factory()->create();
    $actor = catalogueActor('create_product_variation');

    $variation = app(AddProductVariation::class)->handle($product, variationAttributes(), 25, $actor);
    $inventory = $variation->inventory()->sole();
    $movement = $inventory->inventoryMovements()->sole();

    // §20: stock changes through movements, never a direct quantity write.
    expect($inventory->current_quantity)->toBe(25)
        ->and($inventory->available())->toBe(25)
        ->and($movement->movement_type)->toBe(InventoryMovementType::InitialStock)
        ->and($movement->quantity)->toBe(25)
        ->and($movement->created_by_id)->toBe($actor->getKey());
});

it('rejects negative opening stock', function (): void {
    $product = Product::factory()->create();

    expect(fn () => app(AddProductVariation::class)->handle($product, variationAttributes(), -1, null))
        ->toThrow(InvalidArgumentException::class);

    // A caller bug, not a customer-facing condition — but it must still leave
    // nothing behind, because chk_inventories_current_quantity_non_negative
    // would otherwise reject it as a 500 one statement later.
    expect(ProductVariation::count())->toBe(0)
        ->and(Inventory::count())->toBe(0);
});

it('leaves no variation behind when the stock row cannot be written', function (): void {
    $product = Product::factory()->create();

    // Fault injection, because nothing in the schema can make the second
    // insert fail on its own — the variation is created first, so its own
    // unique SKU cannot collide with a row that does not exist yet. Without
    // this the Action's DB::transaction would be a decoration: no test could
    // tell it apart from its absence.
    //
    // The listener is registered on the dispatcher the test's application
    // instance owns, which is rebuilt for the next test.
    Inventory::creating(function (): void {
        throw new RuntimeException('inventory write failed');
    });

    expect(fn () => app(AddProductVariation::class)->handle($product, variationAttributes(), 0, null))
        ->toThrow(RuntimeException::class);

    expect(ProductVariation::count())->toBe(0)
        ->and(Inventory::count())->toBe(0);
});

it('refuses to add a variation to a removed product', function (): void {
    $product = Product::factory()->create();
    $product->delete();

    // ProductResource drops the SoftDeletingScope from its route binding, so
    // the panel can open a deleted product's edit page — and its relation
    // managers with it. The in-memory $product answers every accessor exactly
    // as it did before the delete.
    expect(fn () => app(AddProductVariation::class)->handle($product, variationAttributes(), 0, null))
        ->toThrow(RemovedFromCatalogueException::class);

    expect(ProductVariation::count())->toBe(0)
        ->and(Inventory::count())->toBe(0);
});

it('authorizes before validating the quantity', function (): void {
    $product = Product::factory()->create();
    $actor = catalogueActor('update_product_variation');

    // A negative quantity is also wrong, but an actor who may not create
    // variations at all should be told that, not which argument was bad.
    expect(fn () => app(AddProductVariation::class)->handle($product, variationAttributes(), -5, $actor))
        ->toThrow(AuthorizationException::class);
});

it('denies an actor without create_product_variation', function (): void {
    $product = Product::factory()->create();
    $actor = catalogueActor('create_product');

    expect(fn () => app(AddProductVariation::class)->handle($product, variationAttributes(), 0, $actor))
        ->toThrow(AuthorizationException::class);

    // The denial has to happen before the write, not alongside it.
    expect(ProductVariation::count())->toBe(0)
        ->and(Inventory::count())->toBe(0);
});

it('allows an actor holding create_product_variation', function (): void {
    $product = Product::factory()->create();
    $actor = catalogueActor('create_product_variation');

    $variation = app(AddProductVariation::class)->handle($product, variationAttributes(), 0, $actor);

    expect($variation->exists)->toBeTrue();
});

it('promotes the first variation to default for an actor holding only create_product_variation', function (): void {
    // Regression: SetDefaultVariation authorizes update_product_variation,
    // a permission distinct from create_product_variation on this policy
    // (unlike ProductImagePolicy, where create and update collapse to the
    // same update_product). Composing it with $actor passed through would
    // demand a second permission this actor never held, for an internal
    // structural consequence of the create this actor was already
    // authorized for — not a second discretionary act.
    $product = Product::factory()->create();
    $actor = catalogueActor('create_product_variation');

    $variation = app(AddProductVariation::class)->handle($product, variationAttributes(), 0, $actor);

    expect($variation->is_default)->toBeTrue();
});

it('gives the variation its attribute-value combination when attribute_value_ids is supplied', function (): void {
    // The perfume case: a product with Scent and Volume as its variation
    // axes, a variation created with one value from each in the same call
    // that creates the SKU and stock row — no separate step to forget.
    $product = Product::factory()->create();
    $scent = Attribute::factory()->create();
    $volume = Attribute::factory()->create();
    $product->attributes()->attach([$scent->id, $volume->id]);
    $vanilla = AttributeValue::factory()->for($scent)->create();
    $fiftyMl = AttributeValue::factory()->for($volume)->create();

    $variation = app(AddProductVariation::class)->handle(
        $product,
        variationAttributes(['attribute_value_ids' => [$vanilla->id, $fiftyMl->id]]),
        0,
        null,
    );

    expect($variation->attributeValues()->pluck('attribute_values.id')->sort()->values()->all())
        ->toBe(collect([$vanilla->id, $fiftyMl->id])->sort()->values()->all());
});

it('leaves no variation behind when the attribute-value combination is refused', function (): void {
    // A value belonging to a different product's attribute — the transaction
    // this composes into has to roll the variation back with it, not leave a
    // SKU sitting with no combination.
    $product = Product::factory()->create();
    $foreignValue = AttributeValue::factory()->create();

    expect(fn () => app(AddProductVariation::class)->handle(
        $product,
        variationAttributes(['attribute_value_ids' => [$foreignValue->id]]),
        0,
        null,
    ))->toThrow(AttributeValueNotOnProductException::class);

    expect(ProductVariation::count())->toBe(0)
        ->and(Inventory::count())->toBe(0);
});

it('skips the policy for a null actor', function (): void {
    $product = Product::factory()->create();

    // ADR-0007: null is the application acting on its own behalf — a seeder,
    // a fixture loader, a queued job. Gate::allows() with no user denies
    // everything, so those callers could not run at all otherwise.
    $variation = app(AddProductVariation::class)->handle($product, variationAttributes(), 0, null);

    expect($variation->exists)->toBeTrue()
        ->and($variation->inventory()->sole()->inventoryMovements()->count())->toBe(0);
});
