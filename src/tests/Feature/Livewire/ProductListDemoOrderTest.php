<?php

declare(strict_types=1);

use App\Livewire\Catalogue\ProductList;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\System\PermissionSeeder;
use Database\Seeders\System\RoleSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * The staff-only "Demo order" sort walks a curated sequence of products,
 * each showcasing one distinguishable catalogue case —
 * docs/reference/demo-showcase-order.md has the full list and reasoning.
 * isDemoModeAvailable() is checked both where the sort button renders and
 * where setSortOrder()/the query actually apply it, so the two cannot
 * disagree — these tests prove that pairing, not just "does sorting work."
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
});

it('shows only the curated products, in order, excluding everything else', function (): void {
    $staff = User::factory()->create(['is_active' => true]);
    $staff->assignRole('administrator');

    $second = Product::factory()->create([
        'sku' => 'DEMO-TEST-1B', 'demo_case_order' => 2, 'demo_case_label' => 'Second case', 'is_available' => true,
    ]);
    $first = Product::factory()->create([
        'sku' => 'DEMO-TEST-1A', 'demo_case_order' => 1, 'demo_case_label' => 'First case', 'is_available' => true,
    ]);
    // is_available explicit, not left to the factory's fake()->boolean() —
    // applyFilters()'s unconditional is_available=true clause would
    // otherwise filter a row out roughly half the time, unrelated to what
    // this test is actually proving.
    $notCurated = Product::factory()->create(['sku' => 'DEMO-TEST-2', 'demo_case_order' => null, 'is_available' => true]);

    $ids = Livewire::actingAs($staff)->test(ProductList::class)
        ->call('setSortOrder', 'demo_case_order')
        ->assertSet('sortBy', 'demo_case_order')
        ->viewData('products')
        ->pluck('id')
        ->all();

    expect($ids)->toBe([$first->id, $second->id])
        ->and($ids)->not->toContain($notCurated->id);
});

it('ignores every other active filter once demo mode is on', function (): void {
    $staff = User::factory()->create(['is_active' => true]);
    $staff->assignRole('administrator');

    $curated = Product::factory()->create([
        'sku' => 'DEMO-TEST-6',
        'demo_case_order' => 1,
        'demo_case_label' => 'Survives an unrelated search',
        'name' => 'Totally Unrelated Name',
        'is_available' => true,
    ]);

    $ids = Livewire::actingAs($staff)->test(ProductList::class)
        // A search term that matches nothing about $curated — under the
        // normal (non-demo) path this would exclude it entirely.
        ->set('search', 'this matches nothing real')
        ->call('setSortOrder', 'demo_case_order')
        ->viewData('products')
        ->pluck('id')
        ->all();

    expect($ids)->toContain($curated->id);
});

it('does not apply the demo sort for a guest even via setSortOrder directly', function (): void {
    Product::factory()->create(['sku' => 'DEMO-TEST-3', 'demo_case_order' => 1]);

    Livewire::test(ProductList::class)
        ->call('setSortOrder', 'demo_case_order')
        ->assertSet('sortBy', 'created_at');
});

it('shows the case-order badge in demo mode but not under a normal sort', function (): void {
    $staff = User::factory()->create(['is_active' => true]);
    $staff->assignRole('administrator');

    Product::factory()->create([
        'sku' => 'DEMO-TEST-4',
        'demo_case_order' => 1,
        'demo_case_label' => 'Unique Showcase Label',
        'is_available' => true,
    ]);

    $demo = Livewire::actingAs($staff)->test(ProductList::class)
        ->call('setSortOrder', 'demo_case_order');
    expect($demo->html())->toContain('Unique Showcase Label');

    $normal = Livewire::actingAs($staff)->test(ProductList::class);
    expect($normal->html())->not->toContain('Unique Showcase Label');
});

it('guest never sees the case-order badge even with demo_case_order forced in state', function (): void {
    Product::factory()->create([
        'sku' => 'DEMO-TEST-5',
        'demo_case_order' => 1,
        'demo_case_label' => 'Should Never Leak',
        'is_available' => true,
    ]);

    $component = Livewire::test(ProductList::class)->set('sortBy', 'demo_case_order');

    expect($component->html())->not->toContain('Should Never Leak');
});
