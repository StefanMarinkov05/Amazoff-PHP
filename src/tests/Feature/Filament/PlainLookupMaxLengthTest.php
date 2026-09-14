<?php

declare(strict_types=1);

use App\Enums\AttributeInputType;
use App\Filament\Resources\ArticleCategories\Pages\CreateArticleCategory;
use App\Filament\Resources\Attributes\Pages\CreateAttribute;
use App\Filament\Resources\AttributeValues\Pages\CreateAttributeValue;
use App\Filament\Resources\Brands\Pages\CreateBrand;
use App\Filament\Resources\ProductCategories\Pages\CreateProductCategory;
use App\Filament\Resources\Tags\Pages\CreateTag;
use App\Models\ArticleCategory;
use App\Models\Attribute;
use App\Models\Brand;
use App\Models\ProductCategory;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\System\PermissionSeeder;
use Database\Seeders\System\RoleSeeder;
use Database\Seeders\System\UserSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * SEC-017. Every TextInput on these six plain-lookup resources' create forms
 * (ADR-0007: no Action, default Filament CRUD) carried no ->maxLength(),
 * so a value past the underlying migration's column length reached an
 * INSERT raw and overflowed it — an uncaught QueryException, confirmed live
 * before this fix, found during the content_editor role-scoped ZAP scan.
 * Filament's own client-side maxlength attribute and server-side validation
 * rule both come from ->maxLength() alone; without it there is no ceiling
 * anywhere in the stack.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail());
});

it('refuses an ArticleCategory name past the varchar(50) column instead of crashing', function (): void {
    Livewire::test(CreateArticleCategory::class)
        ->fillForm([
            'name' => str_repeat('x', 51),
            'slug' => 'probe-name',
        ])
        ->call('create')
        ->assertHasFormErrors(['name']);

    expect(ArticleCategory::where('slug', 'probe-name')->exists())->toBeFalse();
});

it('refuses a Tag name past the varchar(30) column instead of crashing', function (): void {
    Livewire::test(CreateTag::class)
        ->fillForm([
            'name' => str_repeat('x', 31),
            'slug' => 'probe-name',
        ])
        ->call('create')
        ->assertHasFormErrors(['name']);

    expect(Tag::where('slug', 'probe-name')->exists())->toBeFalse();
});

it('refuses an Attribute name past the varchar(50) column instead of crashing', function (): void {
    Livewire::test(CreateAttribute::class)
        ->fillForm([
            'name' => str_repeat('x', 51),
            'slug' => 'probe-name',
            'input_type' => AttributeInputType::Text->value,
            'is_filterable' => false,
            'is_variation_only' => false,
        ])
        ->call('create')
        ->assertHasFormErrors(['name']);

    expect(Attribute::where('slug', 'probe-name')->exists())->toBeFalse();
});

it('refuses a Brand name past the varchar(50) column instead of crashing', function (): void {
    Livewire::test(CreateBrand::class)
        ->fillForm([
            'name' => str_repeat('x', 51),
            'slug' => 'probe-name',
        ])
        ->call('create')
        ->assertHasFormErrors(['name']);

    expect(Brand::where('slug', 'probe-name')->exists())->toBeFalse();
});

it('refuses a ProductCategory name past the varchar(50) column instead of crashing', function (): void {
    Livewire::test(CreateProductCategory::class)
        ->fillForm([
            'name' => str_repeat('x', 51),
            'slug' => 'probe-name',
        ])
        ->call('create')
        ->assertHasFormErrors(['name']);

    expect(ProductCategory::where('slug', 'probe-name')->exists())->toBeFalse();
});

it('refuses an AttributeValue value past the varchar(100) column instead of crashing', function (): void {
    $attribute = Attribute::factory()->create();

    Livewire::test(CreateAttributeValue::class)
        ->fillForm([
            'attribute_id' => $attribute->getKey(),
            'value' => str_repeat('x', 101),
            'slug' => 'probe-value',
        ])
        ->call('create')
        ->assertHasFormErrors(['value']);
});

it('refuses an AttributeValue color_hex past the char(7) column instead of crashing', function (): void {
    $attribute = Attribute::factory()->create();

    Livewire::test(CreateAttributeValue::class)
        ->fillForm([
            'attribute_id' => $attribute->getKey(),
            'value' => 'Probe value',
            'slug' => 'probe-color',
            'color_hex' => str_repeat('x', 8),
        ])
        ->call('create')
        ->assertHasFormErrors(['color_hex']);
});
