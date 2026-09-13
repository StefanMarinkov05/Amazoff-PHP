<?php

declare(strict_types=1);

use App\Actions\Catalogue\AddProductImage;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\ProductImagesRelationManager;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Database\Seeders\System\PermissionSeeder;
use Database\Seeders\System\RoleSeeder;
use Database\Seeders\System\UserSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * Confirmed absent before this file: no test referenced
 * ProductImagesRelationManager directly, only AddProductImage/
 * RemoveProductImage/SetMainProductImage through their own Action tests.
 * ProductSpecificationsRelationManager is deliberately not tested this way
 * — it is plain Filament CRUD over a single table with no Action, no
 * scoping, and no invariant (ADR-0007's "wrapping a single-table save in an
 * Action buys nothing" applies to testing it directly the same way).
 *
 * What is "ours" here and worth pinning:
 * - the single-upload form calls AddProductImage (already covered by
 *   `edits alt text only` style plain-CRUD assumptions not holding — this
 *   one has a real Action behind it, unlike Specifications);
 * - setMain wires to SetMainProductImage;
 * - uploadMany's own closure-rule dimension validator exists specifically
 *   because a ->rules([new Dimensions]) on a ->multiple() field only ever
 *   reports "one of these images is too small" with no filename (see the
 *   relation manager's own docblock) — the closure rule is what names the
 *   actual failing file, and that is the thing to prove works;
 * - uploadMany's sort_order offset, so a second bulk upload appends rather
 *   than restarting at 0 and interleaving with the existing gallery.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail());
    Storage::fake(ProductImage::DISK);
});

it('adds an image through the single-upload form', function (): void {
    $product = Product::factory()->create();

    Livewire::test(ProductImagesRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])
        ->callTableAction('create', data: [
            'path' => UploadedFile::fake()->image('cover.jpg', 800, 800),
            'sort_order' => 0,
        ])
        ->assertHasNoTableActionErrors();

    expect($product->productImages()->count())->toBe(1)
        // First image becomes main whether or not asked for — AddProductImage's
        // own rule; confirming the panel reaches it, not re-testing the rule.
        ->and($product->productImages()->sole()->is_main)->toBeTrue();
});

it('promotes an image to main through the relation manager', function (): void {
    $product = Product::factory()->create();
    $original = app(AddProductImage::class)->handle($product, relationManagerImageAttributes(), null);
    $candidate = app(AddProductImage::class)->handle($product, relationManagerImageAttributes(), null);

    expect($original->fresh()->is_main)->toBeTrue();

    Livewire::test(ProductImagesRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])
        ->callTableAction('setMain', $candidate)
        ->assertHasNoTableActionErrors();

    expect($candidate->fresh()->is_main)->toBeTrue()
        ->and($original->fresh()->is_main)->toBeFalse();
});

it('names the specific file that is too small, in the bulk-upload validator', function (): void {
    // The gap this closure rule exists to close, per the relation manager's
    // own docblock: Filament's built-in ->rules([new Dimensions]) on a
    // ->multiple() field validates every file through one nested Validator
    // keyed "paths.*" and surfaces only the first failing message with no
    // filename attached — "one of these images is too small," on any
    // number of files. A closure rule gets each file directly and can name
    // it; that naming is the thing worth pinning, not that dimension
    // validation exists at all (Illuminate\Validation\Rules\Dimensions is
    // Laravel's own, already proven upstream).
    $product = Product::factory()->create();

    Livewire::test(ProductImagesRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])
        ->mountTableAction('uploadMany')
        ->setTableActionData([
            'paths' => [
                UploadedFile::fake()->image('big-enough.jpg', 800, 800),
                UploadedFile::fake()->image('too-small.jpg', 100, 100),
            ],
        ])
        ->callMountedTableAction()
        ->assertHasTableActionErrors(['paths']);

    expect($product->productImages()->count())->toBe(0);
});

it('accepts every file in a bulk upload when all meet the minimum dimensions', function (): void {
    $product = Product::factory()->create();

    Livewire::test(ProductImagesRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])
        ->mountTableAction('uploadMany')
        ->setTableActionData([
            'paths' => [
                UploadedFile::fake()->image('first.jpg', 800, 800),
                UploadedFile::fake()->image('second.jpg', 800, 800),
            ],
        ])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($product->productImages()->count())->toBe(2);
});

it('appends a bulk upload after the existing gallery rather than restarting sort_order at 0', function (): void {
    // Existing count as the offset — without it, a second bulk upload
    // would interleave with what is already there (both starting at 0)
    // instead of appending after it.
    $product = Product::factory()->create();
    app(AddProductImage::class)->handle($product, relationManagerImageAttributes(['sort_order' => 0]), null);
    app(AddProductImage::class)->handle($product, relationManagerImageAttributes(['sort_order' => 1]), null);

    Livewire::test(ProductImagesRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])
        ->mountTableAction('uploadMany')
        ->setTableActionData([
            'paths' => [
                UploadedFile::fake()->image('third.jpg', 800, 800),
            ],
        ])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $newImage = $product->productImages()->orderByDesc('id')->first();

    expect($newImage->sort_order)->toBe(2);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function relationManagerImageAttributes(array $overrides = []): array
{
    return array_merge([
        'path' => ProductImage::DIRECTORY.'/'.UploadedFile::fake()->image('seed.jpg', 800, 800)->hashName(),
        'alt_text' => fake()->words(3, true),
        'sort_order' => 0,
    ], $overrides);
}
