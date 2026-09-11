<?php

declare(strict_types=1);

use App\Actions\Catalogue\AddProductVariation;
use App\Filament\Resources\Brands\Pages\ListBrands;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\User;
use Database\Seeders\System\PermissionSeeder;
use Database\Seeders\System\RoleSeeder;
use Database\Seeders\System\UserSeeder;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;

use Spatie\Permission\PermissionRegistrar;

/*
 * DomainDeleteBulkAction — the bulk delete path routed through each
 * resource's per-record delete Action instead of Filament's default
 * per-record $record->delete().
 *
 * Why this file exists: routing a resource's *single* delete through an
 * Action does nothing for the bulk path, which is a separate call site
 * Filament wires up by default. Confirmed on this codebase in
 * phase-4-admin-panel-clickthrough.md — Brand's bulk delete raised an
 * uncaught QueryException, and Product's *succeeded silently* because
 * Product soft-deletes, skipping DeleteProduct's variation cascade.
 *
 * Each test here goes red if the resource's DomainDeleteBulkAction::make()
 * is reverted to a plain DeleteBulkAction::make():
 *  - "cascades to variations" — the plain action trashes only the products
 *    row, leaving variations reservable.
 *  - "refuses an in-use brand" — the plain action lets the foreign key
 *    surface as an uncaught QueryException.
 *  - "all-or-nothing writes nothing" — no such action exists on the plain
 *    path (assertTableBulkActionExists fails), and the free brand is deleted.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail());
});

it('cascades to variations when products are bulk-deleted', function (): void {
    $products = Product::factory()->count(2)->create(['is_available' => true]);

    foreach ($products as $product) {
        app(AddProductVariation::class)->handle($product, variationAttributes(), 0, null);
    }

    $variationIds = ProductVariation::query()->pluck('id');
    expect($variationIds)->toHaveCount(2);

    Livewire::test(ListProducts::class)
        ->callTableBulkAction('delete', $products);

    // The products themselves are soft-deleted either way — the point is the
    // variations, which the default bulk path leaves untouched (still
    // reservable, because ReserveStock checks the variation, not the product).
    foreach ($variationIds as $id) {
        expect(ProductVariation::withTrashed()->find($id)->trashed())
            ->toBeTrue("variation {$id} should have been trashed with its product");
    }
});

it('refuses an in-use brand with a notification that names the dependency', function (): void {
    $inUse = Brand::factory()->create(['name' => 'Acme']);
    Product::factory()->create(['brand_id' => $inUse->getKey()]);

    $free = Brand::factory()->create();

    Livewire::test(ListBrands::class)
        ->callTableBulkAction('delete', [$inUse->getKey(), $free->getKey()])
        // The message comes from BrandCannotBeDeletedException via DeleteBrand,
        // not Filament's generic "could not be deleted" — that specificity is
        // the whole point of routing the bulk path through the Action.
        ->assertNotified('1 brand could not be deleted');

    // partial: the free one still goes, the in-use one is kept.
    expect(Brand::find($inUse->getKey()))->not->toBeNull();
    expect(Brand::find($free->getKey()))->toBeNull();
});

it('deletes nothing in all-or-nothing mode when one record is refused', function (): void {
    $inUse = Brand::factory()->create();
    Product::factory()->create(['brand_id' => $inUse->getKey()]);

    $free = Brand::factory()->create();

    Livewire::test(ListBrands::class)
        ->assertTableBulkActionExists('deleteAtomic')
        ->callTableBulkAction('deleteAtomic', [$inUse->getKey(), $free->getKey()])
        ->assertNotified();

    assertDatabaseHas('brands', ['id' => $inUse->getKey()]);
    assertDatabaseHas('brands', ['id' => $free->getKey()]);
});
