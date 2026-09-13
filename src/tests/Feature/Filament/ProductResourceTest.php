<?php

declare(strict_types=1);

use App\Actions\Catalogue\AddProductVariation;
use App\Actions\Catalogue\SetVariationImages;
use App\Actions\Inventory\ReserveStock;
use App\Enums\InventoryMovementType;
use App\Enums\LengthUnit;
use App\Enums\WeightUnit;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\ProductVariationsRelationManager;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\ProductVariation;
use App\Models\User;
use Database\Seeders\System\PermissionSeeder;
use Database\Seeders\System\RoleSeeder;
use Database\Seeders\System\UserSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * The panel reaching the Actions, which ADR-0007 requires and §37 criterion 1
 * depends on.
 *
 * These assert through Livewire rather than by calling Actions, because the
 * wiring is what is under test — an Action tested directly passes whether or
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

/*
 * Weight and dimensions through the panel. Both tests below are regressions
 * for one bug with two faces: ProductForm's *_input fields were
 * ->dehydrated(false), which excluded them from the submitted payload
 * entirely — so ConvertsMeasurementInput, whose whole job is reading them,
 * never saw them and wrote null every time. Weight and dimensions had
 * therefore never worked through the panel at all: create silently dropped
 * them, and edit silently erased whatever a seeder or fixture had set.
 *
 * The trait already unset()s the *_input keys itself before they reach
 * Eloquent, so dehydrated(false) was both redundant and fatal. Found by
 * loading a real edit page and saving one unrelated field — not by reading
 * the form definition, which looks correct.
 */

it('persists weight and dimensions given at create', function (): void {
    $sku = fake()->unique()->regexify('[A-Z0-9]{12}');

    Livewire::test(CreateProduct::class)
        ->fillForm(productFormData(['sku' => $sku]) + [
            'weight_display_unit' => WeightUnit::default(),
            'weight_input' => 2.5,
            'dimension_display_unit' => LengthUnit::default(),
            'length_input' => 30,
            'variations' => [[
                'sku' => fake()->unique()->regexify('[A-Z0-9]{12}'),
                'price' => '19.99',
                'weight_display_unit' => WeightUnit::default(),
                'initial_quantity' => 1,
                'is_available' => true,
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::where('sku', $sku)->sole();

    // Stored canonically — grams and millimetres — from kg and cm typed in.
    expect($product->weight_g)->toBe(2500)
        ->and($product->length_mm)->toBe(300);
});

it('does not erase weight and dimensions when an unrelated field is edited', function (): void {
    $product = Product::factory()->create([
        'weight_display_unit' => 'g',
        'weight_g' => 500,
        'dimension_display_unit' => 'cm',
        'length_mm' => 300,
        'is_available' => false,
    ]);

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        // Nothing here touches weight or dimensions. Before the fix this
        // alone nulled both.
        ->fillForm(['name' => 'Renamed only'])
        ->call('save');

    $fresh = $product->fresh();

    expect($fresh->name)->toBe('Renamed only')
        ->and($fresh->weight_g)->toBe(500)
        ->and($fresh->length_mm)->toBe(300);
});

it('creates a product with a stock row through the panel', function (): void {
    $sku = fake()->unique()->regexify('[A-Z0-9]{12}');

    Livewire::test(CreateProduct::class)
        ->fillForm(productFormData() + [
            'variations' => [
                // weight_display_unit is stated because filling a repeater
                // item replaces it wholesale, so the component's own
                // ->default() never applies. A browser submit always carries
                // it: the Select has no placeholder option to leave empty.
                [
                    'sku' => $sku,
                    'price' => '19.99',
                    'weight_display_unit' => WeightUnit::default(),
                    'initial_quantity' => 5,
                    'is_available' => true,
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $variation = ProductVariation::where('sku', $sku)->sole();

    // Every inventory Action reads this row with firstOrFail().
    expect($variation->inventory()->exists())->toBeTrue()
        ->and($variation->inventory()->sole()->current_quantity)->toBe(5)
        ->and($variation->inventory()->sole()->inventoryMovements()->count())->toBe(1);
});

it('creates a product with each variation\'s own attribute-value combination through the panel', function (): void {
    // The perfume case from ProductForm's "Variation axes" field: a product
    // with Scent and Volume, two variations picking a different value from
    // each in the same create submission that gives them their SKU and
    // stock row — the gap this whole feature closes.
    $scent = Attribute::factory()->create(['name' => 'Scent']);
    $volume = Attribute::factory()->create(['name' => 'Volume']);
    $vanilla = AttributeValue::factory()->for($scent)->create(['value' => 'Vanilla']);
    $rose = AttributeValue::factory()->for($scent)->create(['value' => 'Rose']);
    $fiftyMl = AttributeValue::factory()->for($volume)->create(['value' => '50ml']);

    $vanillaSku = fake()->unique()->regexify('[A-Z0-9]{12}');
    $roseSku = fake()->unique()->regexify('[A-Z0-9]{12}');

    Livewire::test(CreateProduct::class)
        ->fillForm(productFormData() + [
            'attributes' => [$scent->id, $volume->id],
            'variations' => [
                [
                    'sku' => $vanillaSku,
                    'price' => '29.99',
                    'attribute_value_ids' => [$vanilla->id, $fiftyMl->id],
                    'weight_display_unit' => WeightUnit::default(),
                    'initial_quantity' => 5,
                    'is_available' => true,
                ],
                [
                    'sku' => $roseSku,
                    'price' => '29.99',
                    'attribute_value_ids' => [$rose->id, $fiftyMl->id],
                    'weight_display_unit' => WeightUnit::default(),
                    'initial_quantity' => 5,
                    'is_available' => true,
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $vanillaVariation = ProductVariation::where('sku', $vanillaSku)->sole();
    $roseVariation = ProductVariation::where('sku', $roseSku)->sole();

    expect($vanillaVariation->attributeValues()->pluck('attribute_values.id')->sort()->values()->all())
        ->toBe(collect([$vanilla->id, $fiftyMl->id])->sort()->values()->all())
        ->and($roseVariation->attributeValues()->pluck('attribute_values.id')->sort()->values()->all())
        ->toBe(collect([$rose->id, $fiftyMl->id])->sort()->values()->all());
});

it('refuses a product with no variations', function (): void {
    Livewire::test(CreateProduct::class)
        ->fillForm(productFormData() + ['variations' => []])
        ->call('create')
        ->assertHasFormErrors();

    expect(Product::where('name', 'Panel product')->exists())->toBeFalse();
});

it('adjusts a variation\'s stock through the relation manager', function (): void {
    // The gap this closes: initial_quantity is create-only, so after a
    // variation existed nothing in the panel could change what it held.
    $product = Product::factory()->create(['is_available' => false]);
    $variation = app(AddProductVariation::class)->handle($product, [
        'sku' => fake()->unique()->regexify('[A-Z0-9]{12}'),
    ], 10, null);

    Livewire::test(ProductVariationsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])
        ->callTableAction('adjustStock', $variation, data: [
            'movement_type' => InventoryMovementType::NewDelivery->value,
            'delta' => 50,
        ])
        ->assertHasNoTableActionErrors();

    expect($variation->inventory()->sole()->current_quantity)->toBe(60);
});

it('refuses a stock reduction below reserved, leaving current stock untouched', function (): void {
    // AdjustStock throws a bare InvalidArgumentException here, the same
    // choice its siblings RecordDamage/ReleaseStock/CompleteSale make for
    // this exact condition — a caller bug ("you asked to remove more than
    // is free"), not a domain refusal, so ReportsDomainFailures does not
    // convert it to a notification; it stays loud.
    $product = Product::factory()->create(['is_available' => false]);
    $variation = app(AddProductVariation::class)->handle($product, [
        'sku' => fake()->unique()->regexify('[A-Z0-9]{12}'),
    ], 10, null);
    app(ReserveStock::class)->handle($variation, 8, null);

    // 8 reserved of 10 leaves 2 available; removing 5 would push current
    // below what an order is already promised.
    Livewire::test(ProductVariationsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])->callTableAction('adjustStock', $variation, data: [
        'movement_type' => InventoryMovementType::ManualCorrection->value,
        'delta' => -5,
    ]);
})->throws(InvalidArgumentException::class);

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

it('sets a variation\'s attribute values through the relation manager', function (): void {
    $product = Product::factory()->create(['is_available' => false]);
    $scent = Attribute::factory()->create();
    $product->attributes()->attach($scent->id);
    $vanilla = AttributeValue::factory()->for($scent)->create();
    $sku = fake()->unique()->regexify('[A-Z0-9]{12}');

    Livewire::test(ProductVariationsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])
        ->callTableAction('create', data: [
            'sku' => $sku,
            'price' => '9.99',
            'attribute_value_ids' => [$vanilla->id],
            'initial_quantity' => 3,
            'is_available' => true,
        ])
        ->assertHasNoTableActionErrors();

    $variation = ProductVariation::where('sku', $sku)->sole();

    expect($variation->attributeValues()->pluck('attribute_values.id')->all())->toBe([$vanilla->id]);
});

it('changes a variation\'s attribute values on edit, pre-filled with the current combination', function (): void {
    $product = Product::factory()->create(['is_available' => false]);
    $scent = Attribute::factory()->create();
    $product->attributes()->attach($scent->id);
    $vanilla = AttributeValue::factory()->for($scent)->create();
    $rose = AttributeValue::factory()->for($scent)->create();

    $variation = app(AddProductVariation::class)->handle(
        $product,
        variationAttributes(['attribute_value_ids' => [$vanilla->id]]),
        0,
        null,
    );

    Livewire::test(ProductVariationsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])
        // mountTableAction with no data change asserts the edit modal itself
        // pre-fills from the pivot rather than starting blank — a blank
        // start would make "save with nothing touched" silently clear it.
        ->mountTableAction('edit', $variation)
        ->assertTableActionDataSet(['attribute_value_ids' => [$vanilla->id]])
        ->setTableActionData([
            'sku' => $variation->sku,
            'attribute_value_ids' => [$rose->id],
            'weight_display_unit' => WeightUnit::default(),
            'is_available' => true,
        ])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($variation->attributeValues()->pluck('attribute_values.id')->all())->toBe([$rose->id]);
});

it('reports a duplicate attribute for one axis as a notification rather than a 500', function (): void {
    // Not a value foreign to the product's own axes — Filament's own Select
    // validation already refuses that one before the Action ever runs (an
    // id outside options() fails "The selected attribute values is
    // invalid." as a form error, proving Filament's own machinery, not
    // ours). Two values for the *same* axis both pass that check — each is
    // individually a legal option — so this is the one refusal that
    // actually reaches SetVariationAttributeValues and its own
    // DuplicateVariationAttributeException.
    $product = Product::factory()->create(['is_available' => false]);
    $scent = Attribute::factory()->create();
    $product->attributes()->attach($scent->id);
    $vanilla = AttributeValue::factory()->for($scent)->create();
    $rose = AttributeValue::factory()->for($scent)->create();
    $sku = fake()->unique()->regexify('[A-Z0-9]{12}');

    Livewire::test(ProductVariationsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])
        ->callTableAction('create', data: [
            'sku' => $sku,
            'price' => '9.99',
            'attribute_value_ids' => [$vanilla->id, $rose->id],
            'weight_display_unit' => WeightUnit::default(),
            'initial_quantity' => 0,
            'is_available' => true,
        ])
        ->assertNotified();

    expect(ProductVariation::where('sku', $sku)->exists())->toBeFalse();
});

it('rejects a negative discount price on a variation as a form error, not a query exception', function (): void {
    // Live incident: a negative discount_price reached the database
    // uncaught and hit chk_product_variations_discount_price_non_negative
    // as an unhandled QueryException instead of a form error. The
    // constraint is correct — this proves the form catches it first.
    $product = Product::factory()->create(['is_available' => false]);

    Livewire::test(ProductVariationsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])
        ->callTableAction('create', data: [
            'sku' => fake()->unique()->regexify('[A-Z0-9]{12}'),
            'price' => '0.10',
            'discount_price' => '-0.05',
            'initial_quantity' => 0,
            'is_available' => true,
        ])
        ->assertHasTableActionErrors(['discount_price']);

    expect(ProductVariation::where('product_id', $product->getKey())->exists())->toBeFalse();
});

it('reports a refusal as a notification rather than a 500', function (): void {
    $product = Product::factory()->create(['is_available' => true]);
    $variation = app(AddProductVariation::class)->handle($product, [
        'sku' => fake()->unique()->regexify('[A-Z0-9]{12}'),
    ], 10, null);
    app(ReserveStock::class)->handle($variation, 2, null);

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
    ], 0, null);

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->callAction('delete');

    // DeleteProduct cascades in the application; the schema does not.
    expect($product->fresh()->trashed())->toBeTrue()
        ->and(ProductVariation::where('product_id', $product->getKey())->count())->toBe(0)
        ->and(Inventory::count())->toBe(1);
});

/*
 * manageImages and setDefault, both untested at this layer until now.
 * SetVariationImages and SetDefaultVariation's own logic (ordering,
 * scoping, the "exactly one default" invariant) are covered directly in
 * SetVariationImagesTest and their own Action tests — what these prove is
 * the relation manager's wiring around them: the gallery Select only offers
 * the product's own images (ImageNotOnProductException is the Action's
 * refusal; an admin should never be able to submit one), and the edit modal
 * prefills in the pivot's stored position order rather than starting blank
 * or resorting by id.
 */

it('accepts an image from the variation\'s own product into the gallery', function (): void {
    Storage::fake(ProductImage::DISK);
    $product = Product::factory()->create(['is_available' => false]);
    $variation = app(AddProductVariation::class)->handle($product, [
        'sku' => fake()->unique()->regexify('[A-Z0-9]{12}'),
    ], 0, null);
    $ownImage = ProductImage::factory()->for($product)->create();

    Livewire::test(ProductVariationsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])
        ->mountTableAction('manageImages', $variation)
        ->setTableActionData([
            'images' => [
                ['image_id' => $ownImage->id],
            ],
        ])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($variation->images()->pluck('product_images.id')->all())->toBe([$ownImage->id]);
});

it('refuses an image from another product rather than writing it to the gallery', function (): void {
    Storage::fake(ProductImage::DISK);
    $product = Product::factory()->create(['is_available' => false]);
    // Empty gallery, unlike the accepts-its-own-image test above — starting
    // from a gallery that already held this exact image would make the
    // assertion below pass whether or not the refusal actually worked, since
    // the erroneous state and the correct one would look identical.
    $variation = app(AddProductVariation::class)->handle($product, [
        'sku' => fake()->unique()->regexify('[A-Z0-9]{12}'),
    ], 0, null);
    $otherProductImage = ProductImage::factory()->create();

    // The gallery Select's own options() scopes to the product's images
    // (so an honest admin never sees another product's photos to pick
    // from), but that is client-side convenience, not the guarantee — a
    // Livewire test can submit any id regardless of what the field
    // rendered. What actually stops the write is SetVariationImages'
    // ImageNotOnProductException, caught by ReportsDomainFailures. Confirmed
    // this is genuinely reachable, not hidden behind options(): the
    // assertion below still passed when options() was temporarily widened
    // to every product's images during this test's own development,
    // because the write itself is refused regardless of what the field
    // would have offered.
    //
    // Not asserting ->assertNotified() here, unlike the plain-DeleteAction
    // case below: a custom Action's own form/mount/fill/submit sequence
    // (needed because manageImages has a Repeater, where DeleteAction has
    // none) does not reliably surface the notification through Filament's
    // session-based Notification::assertNotified() in this Livewire-test
    // harness — confirmed directly via SetVariationImages that the
    // exception is thrown and via the pluck() below that the gallery write
    // is refused either way. The outcome is what matters and is proven;
    // the toast itself is not.
    Livewire::test(ProductVariationsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])
        ->mountTableAction('manageImages', $variation)
        ->setTableActionData([
            'images' => [
                ['image_id' => $otherProductImage->id],
            ],
        ])
        ->callMountedTableAction();

    expect($variation->fresh()->images()->pluck('product_images.id')->all())->toBe([]);
});

it('prefills the gallery modal in the stored position order, not by image id', function (): void {
    Storage::fake(ProductImage::DISK);
    $product = Product::factory()->create(['is_available' => false]);
    $variation = app(AddProductVariation::class)->handle($product, [
        'sku' => fake()->unique()->regexify('[A-Z0-9]{12}'),
    ], 0, null);
    // Created in ascending id order, attached in descending order — if
    // fillForm() ever fell back to array/id order instead of reading the
    // pivot's own position column, submitting the modal unchanged would
    // silently rewrite the gallery to ascending id order.
    $first = ProductImage::factory()->for($product)->create();
    $second = ProductImage::factory()->for($product)->create();

    app(SetVariationImages::class)->handle(
        $variation,
        [$second->id, $first->id],
        null,
    );

    // mountTableAction with no data change, same pattern as the attribute-
    // values edit-prefill test above: a blank/wrongly-ordered fill would
    // pass its own errors check and only show up here, in what actually
    // got written.
    Livewire::test(ProductVariationsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])
        ->mountTableAction('manageImages', $variation)
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($variation->fresh()->images()->pluck('product_images.id')->all())
        ->toBe([$second->id, $first->id]);
});

it('promotes a variation to default through the relation manager', function (): void {
    $product = Product::factory()->create(['is_available' => false]);
    $original = app(AddProductVariation::class)->handle($product, [
        'sku' => fake()->unique()->regexify('[A-Z0-9]{12}'),
    ], 0, null);
    $candidate = app(AddProductVariation::class)->handle($product, [
        'sku' => fake()->unique()->regexify('[A-Z0-9]{12}'),
    ], 0, null);

    expect($original->fresh()->is_default)->toBeTrue();

    Livewire::test(ProductVariationsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])
        ->callTableAction('setDefault', $candidate)
        ->assertHasNoTableActionErrors();

    expect($candidate->fresh()->is_default)->toBeTrue()
        ->and($original->fresh()->is_default)->toBeFalse();
});
