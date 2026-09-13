<?php

declare(strict_types=1);

use App\Actions\Catalogue\DeleteBrand;
use App\Exceptions\BrandCannotBeDeletedException;
use App\Models\Brand;
use App\Models\Product;
use Database\Seeders\System\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\PermissionRegistrar;

/*
 * EditBrand's default DeleteAction previously called $record->delete()
 * directly, surfacing products.brand_id's foreign key as an uncaught
 * QueryException (1451) instead of a message naming the dependency.
 * DeleteBrand is what EditBrand now routes through instead.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed(PermissionSeeder::class);
});

it('deletes a brand with no products', function (): void {
    $brand = Brand::factory()->create();

    app(DeleteBrand::class)->handle($brand, null);

    expect(Brand::find($brand->getKey()))->toBeNull();
});

it('refuses a brand with a product and writes nothing', function (): void {
    $brand = Brand::factory()->create();
    Product::factory()->create(['brand_id' => $brand->getKey()]);

    expect(fn () => app(DeleteBrand::class)->handle($brand, null))
        ->toThrow(BrandCannotBeDeletedException::class);

    expect(Brand::find($brand->getKey()))->not->toBeNull();
});

it('denies an actor without delete_brand', function (): void {
    $brand = Brand::factory()->create();
    $actor = catalogueActor('update_brand');

    expect(fn () => app(DeleteBrand::class)->handle($brand, $actor))
        ->toThrow(AuthorizationException::class);

    expect(Brand::find($brand->getKey()))->not->toBeNull();
});

it('allows an actor holding delete_brand', function (): void {
    $brand = Brand::factory()->create();
    $actor = catalogueActor('delete_brand');

    app(DeleteBrand::class)->handle($brand, $actor);

    expect(Brand::find($brand->getKey()))->toBeNull();
});
