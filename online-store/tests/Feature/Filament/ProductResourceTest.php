<?php

declare(strict_types=1);

use App\Actions\Catalogue\AddProductVariation;
use App\Actions\Inventory\ReserveStock;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\ProductVariationsRelationManager;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariation;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * The panel reaching the Actions. ADR-0007 requires it and §37 criterion 1
 * depends on it; until this existed the resource wrote Eloquent directly and
 * every variation it created had no `inventories` row.
 *
 * These assert through Livewire rather than by calling Actions, because what
 * is under test is the wiring — an Action tested directly passes whether or
 * not anything calls it.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail());
});

/** @return array<string, mixed> */
function productFormData(array $overrides = []): array
{
    return array_merge([
        'product_category_id' => ProductCategory::factory()->create()->getKey(),
        'name' => 'Panel product',
        'slug' => fake()->unique()->slug(3),
        'sku' => fake()->unique()->regexify('[A-Z0-9]{12}'),
        'regular_price' => '189.90',
        'vat_rate' => '20.00',
        'min_order_quantity' => 1,
        'is_available' => true,
        'is_featured' => false,
    ], $overrides);
}

it('creates a product with a stock row through the panel', function (): void {
    $sku = fake()->unique()->regexify('[A-Z0-9]{12}');

    Livewire::test(CreateProduct::class)
        ->fillForm(productFormData() + [
            'variations' => [
                ['sku' => $sku, 'price' => '19.99', 'initial_quantity' => 5, 'is_available' => true],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $variation = ProductVariation::where('sku', $sku)->sole();

    // The defect this wiring closes: Filament's default create wrote the
    // variation alone, and every inventory Action reads this row with
    // firstOrFail().
    expect($variation->inventory()->exists())->toBeTrue()
        ->and($variation->inventory()->sole()->current_quantity)->toBe(5)
        ->and($variation->inventory()->sole()->inventoryMovements()->count())->toBe(1);
});

it('refuses a product with no variations', function (): void {
    Livewire::test(CreateProduct::class)
        ->fillForm(productFormData() + ['variations' => []])
        ->call('create')
        ->assertHasFormErrors();

    expect(Product::where('name', 'Panel product')->exists())->toBeFalse();
});

it('adds a variation with its stock row through the relation manager', function (): void {
    $product = Product::factory()->create(['is_available' => false]);
    $sku = fake()->unique()->regexify('[A-Z0-9]{12}');

    Livewire::test(ProductVariationsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])
        ->callTableAction('create', data: [
            'sku' => $sku,
            'price' => '9.99',
            'initial_quantity' => 3,
            'is_available' => true,
        ])
        ->assertHasNoTableActionErrors();

    $variation = ProductVariation::where('sku', $sku)->sole();

    expect($variation->product_id)->toBe($product->getKey())
        ->and($variation->inventory()->sole()->current_quantity)->toBe(3);
});

it('reports a refusal as a notification rather than a 500', function (): void {
    $product = Product::factory()->create(['is_available' => true]);
    $variation = app(AddProductVariation::class)->handle($product, [
        'sku' => fake()->unique()->regexify('[A-Z0-9]{12}'),
    ], 10);
    app(ReserveStock::class)->handle($variation, 2);

    // Reserved stock blocks removal. Without the domain-failure trait this is
    // an uncaught exception; with it the administrator gets a message naming
    // the reason.
    Livewire::test(ProductVariationsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])
        ->callTableAction('delete', $variation)
        ->assertNotified();

    expect($variation->fresh()->trashed())->toBeFalse();
});

it('refuses to publish a product whose last variation is gone', function (): void {
    $product = Product::factory()->create(['is_available' => false]);

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->fillForm(['is_available' => true])
        ->call('save')
        ->assertNotified();

    expect($product->fresh()->is_available)->toBeFalse();
});

it('deletes a product and its variations through the panel', function (): void {
    $product = Product::factory()->create(['is_available' => false]);
    app(AddProductVariation::class)->handle($product, [
        'sku' => fake()->unique()->regexify('[A-Z0-9]{12}'),
    ]);

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->callAction('delete');

    // DeleteProduct cascades in the application; the schema does not.
    expect($product->fresh()->trashed())->toBeTrue()
        ->and(ProductVariation::where('product_id', $product->getKey())->count())->toBe(0)
        ->and(Inventory::count())->toBe(1);
});
