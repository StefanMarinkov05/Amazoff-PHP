<?php

declare(strict_types=1);

use App\Filament\Resources\ProductCategories\Pages\EditProductCategory;
use App\Models\ProductCategory;
use App\Models\User;
use Database\Seeders\System\PermissionSeeder;
use Database\Seeders\System\RoleSeeder;
use Database\Seeders\System\UserSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * The panel reaching DeleteProductCategory, the same reason
 * ProductResourceTest asserts through Livewire rather than the Action
 * directly — the wiring is what is under test.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail());
});

it('deletes a category through the panel', function (): void {
    $category = ProductCategory::factory()->create();

    Livewire::test(EditProductCategory::class, ['record' => $category->getKey()])
        ->callAction('delete');

    expect(ProductCategory::find($category->getKey()))->toBeNull();
});

it('reports a category with a subcategory as a notification rather than a 500', function (): void {
    $parent = ProductCategory::factory()->create();
    ProductCategory::factory()->childOf($parent)->create();

    Livewire::test(EditProductCategory::class, ['record' => $parent->getKey()])
        ->callAction('delete')
        ->assertNotified();

    expect(ProductCategory::find($parent->getKey()))->not->toBeNull();
});
