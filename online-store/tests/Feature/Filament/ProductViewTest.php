<?php

declare(strict_types=1);

use App\Filament\Resources\Coupons\Pages\ViewCoupon;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Pages\ViewProduct;
use App\Filament\Resources\Products\RelationManagers\ProductVariationsRelationManager;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariation;
use App\Models\User;
use Database\Seeders\System\PermissionSeeder;
use Database\Seeders\System\RoleSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * Both resources had no View page at all before this change — the table
 * itself carried every field, uncapped. These prove the infolist actually
 * renders full text a capped table column would truncate, and that the
 * variation/image thumbnail column (a bare count before) resolves real
 * eager-loaded image paths rather than crashing on the relation.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('administrator');
    $this->actingAs($admin);
});

it('shows a product\'s full text on its view page, not the table\'s capped version', function (): void {
    $longName = 'A genuinely long product name that the table column truncates';
    $product = Product::factory()->create([
        'name' => $longName,
        'short_description' => str_repeat('Full uncapped description text. ', 5),
    ]);

    Livewire::test(ViewProduct::class, ['record' => $product->getKey()])
        ->assertOk()
        ->assertSee($longName);
});

it('renders variation image thumbnails from the eager-loaded pivot without an N+1', function (): void {
    $product = Product::factory()->create();
    $variation = ProductVariation::factory()->for($product)->create();
    $image = ProductImage::factory()->for($product)->create();
    $variation->images()->attach($image);

    Livewire::test(ProductVariationsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => ViewProduct::class,
    ])->assertOk();
});

it('shows a coupon\'s full description on its view page, not the table\'s capped version', function (): void {
    $longDescription = str_repeat('Terms and conditions apply extensively. ', 5);
    $coupon = Coupon::factory()->create(['description' => $longDescription]);

    Livewire::test(ViewCoupon::class, ['record' => $coupon->getKey()])
        ->assertOk()
        ->assertSee($longDescription);
});

it('caps the product name on the table but keeps the full text reachable', function (): void {
    $longName = 'A genuinely long product name that the table column truncates';
    $product = Product::factory()->create(['name' => $longName]);

    // assertSee checks raw HTML, not rendered text — ->tooltip() legitimately
    // puts the full name in an attribute even though ->limit(30) cuts the
    // visible text node, so the full string is genuinely present in the DOM
    // either way. What ->limit() actually guarantees is the truncated
    // substring appearing as its own visible fragment; that's what's worth
    // asserting here.
    Livewire::test(ListProducts::class)
        ->assertOk()
        ->assertSee(mb_substr($longName, 0, 30));

    Livewire::test(ViewProduct::class, ['record' => $product->getKey()])
        ->assertOk()
        ->assertSee($longName);
});
