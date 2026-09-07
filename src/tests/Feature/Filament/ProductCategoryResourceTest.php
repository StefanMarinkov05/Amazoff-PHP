<?php

declare(strict_types=1);

use App\Actions\Catalogue\UpdateProductCategory;
use App\Exceptions\CategoryCycleException;
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

it('shows a category\'s full ancestry on its edit page', function (): void {
    // The hierarchy is 4 levels deep in the real catalogue, and opening one
    // parent at a time to find out where a category sits was the gap this
    // closes. The breadcrumb is rendered by
    // resources/views/filament/components/category-ancestry.blade.php from
    // ResolveCategoryFamily::ancestryOf(), root first.
    $top = ProductCategory::factory()->create(['name' => 'Clothing']);
    $mid = ProductCategory::factory()->childOf($top)->create(['name' => 'Men']);
    $leaf = ProductCategory::factory()->childOf($mid)->create(['name' => 'Tops']);

    Livewire::test(EditProductCategory::class, ['record' => $leaf->getKey()])
        ->assertOk()
        ->assertSee('Clothing')
        ->assertSee('Men')
        ->assertSee('Tops');
});

it('refuses a cycle at the form, before the Action is reached', function (): void {
    // Two layers, and this is the outer one: the parent dropdown excludes
    // the category's own descendants, so a submitted id that is not among
    // the options fails Filament's own Select validation as a form error.
    // The Action's own refusal is asserted directly below — it cannot be
    // reached through this path precisely because this layer holds.
    $top = ProductCategory::factory()->create();
    $mid = ProductCategory::factory()->childOf($top)->create();
    $leaf = ProductCategory::factory()->childOf($mid)->create();

    Livewire::test(EditProductCategory::class, ['record' => $top->getKey()])
        ->fillForm(['parent_id' => $leaf->getKey()])
        ->call('save')
        ->assertHasFormErrors(['parent_id']);

    expect($top->fresh()->parent_id)->toBeNull();
});

it('refuses a cycle in the Action, whatever the form offered', function (): void {
    // The binding layer. A hidden dropdown option is not enforcement — a
    // second tab open from before the tree was reshaped, a seeder, or a
    // hand-built request all reach the Action directly.
    $top = ProductCategory::factory()->create();
    $mid = ProductCategory::factory()->childOf($top)->create();
    $leaf = ProductCategory::factory()->childOf($mid)->create();

    expect(fn () => app(UpdateProductCategory::class)->handle(
        $top,
        ['parent_id' => $leaf->getKey()],
        null,
    ))->toThrow(CategoryCycleException::class);

    expect($top->fresh()->parent_id)->toBeNull();
});

it('refuses a category as its own parent in the Action', function (): void {
    $category = ProductCategory::factory()->create();

    expect(fn () => app(UpdateProductCategory::class)->handle(
        $category,
        ['parent_id' => $category->getKey()],
        null,
    ))->toThrow(CategoryCycleException::class);

    expect($category->fresh()->parent_id)->toBeNull();
});

it('allows a legal reparent through the panel', function (): void {
    $category = ProductCategory::factory()->create();
    $elsewhere = ProductCategory::factory()->create();

    Livewire::test(EditProductCategory::class, ['record' => $category->getKey()])
        ->fillForm(['parent_id' => $elsewhere->getKey()])
        ->call('save');

    expect($category->fresh()->parent_id)->toBe($elsewhere->getKey());
});

it('does not reset a category\'s parent when an unrelated field is edited', function (): void {
    // UpdateProductCategory only reads parent_id when the key is actually
    // present — the same partial-payload discipline UpdateProduct keeps.
    $parent = ProductCategory::factory()->create();
    $child = ProductCategory::factory()->childOf($parent)->create();

    app(UpdateProductCategory::class)->handle($child, ['name' => 'Renamed'], null);

    expect($child->fresh()->name)->toBe('Renamed')
        ->and($child->fresh()->parent_id)->toBe($parent->getKey());
});
