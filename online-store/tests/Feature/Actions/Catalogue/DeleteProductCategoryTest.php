<?php

declare(strict_types=1);

use App\Actions\Catalogue\DeleteProductCategory;
use App\Exceptions\ProductCategoryCannotBeDeletedException;
use App\Models\Product;
use App\Models\ProductCategory;
use Database\Seeders\System\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\PermissionRegistrar;

/*
 * ProductCategoryPolicy::delete() already named this gap: whether a category
 * may be deleted at all lived in a Filament visible()/disabled() closure,
 * read once when the row rendered rather than when the click landed, and
 * never converted a refusal into a message. DeleteProductCategory is the one
 * place both the storefront and the panel can call to get the same answer.
 *
 * catalogueActor() comes from tests/Pest.php. childOf() comes from
 * ProductCategoryFactory.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed(PermissionSeeder::class);
});

it('deletes a category with no subcategories or products', function (): void {
    $category = ProductCategory::factory()->create();

    app(DeleteProductCategory::class)->handle($category, null);

    expect(ProductCategory::find($category->getKey()))->toBeNull();
});

it('refuses a category with a subcategory and writes nothing', function (): void {
    $parent = ProductCategory::factory()->create();
    ProductCategory::factory()->childOf($parent)->create();

    expect(fn () => app(DeleteProductCategory::class)->handle($parent, null))
        ->toThrow(ProductCategoryCannotBeDeletedException::class);

    expect(ProductCategory::find($parent->getKey()))->not->toBeNull();
});

it('refuses a category with a product and writes nothing', function (): void {
    $category = ProductCategory::factory()->create();
    Product::factory()->create(['product_category_id' => $category->getKey()]);

    expect(fn () => app(DeleteProductCategory::class)->handle($category, null))
        ->toThrow(ProductCategoryCannotBeDeletedException::class);

    expect(ProductCategory::find($category->getKey()))->not->toBeNull();
});

it('denies an actor without delete_product_category', function (): void {
    $category = ProductCategory::factory()->create();
    $actor = catalogueActor('update_product_category');

    expect(fn () => app(DeleteProductCategory::class)->handle($category, $actor))
        ->toThrow(AuthorizationException::class);

    expect(ProductCategory::find($category->getKey()))->not->toBeNull();
});

it('allows an actor holding delete_product_category', function (): void {
    $category = ProductCategory::factory()->create();
    $actor = catalogueActor('delete_product_category');

    app(DeleteProductCategory::class)->handle($category, $actor);

    expect(ProductCategory::find($category->getKey()))->toBeNull();
});

it('skips the policy for a null actor', function (): void {
    $category = ProductCategory::factory()->create();

    app(DeleteProductCategory::class)->handle($category, null);

    expect(ProductCategory::find($category->getKey()))->toBeNull();
});
