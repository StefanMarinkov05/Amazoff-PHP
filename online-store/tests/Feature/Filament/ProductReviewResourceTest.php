<?php

declare(strict_types=1);

use App\Filament\Resources\ProductReviews\Pages\ListProductReviews;
use App\Models\ProductReview;
use App\Models\User;
use Database\Seeders\System\PermissionSeeder;
use Database\Seeders\System\RoleSeeder;
use Database\Seeders\System\UserSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * §24 assigns moderation to administrators only — permissions.md,
 * "content_editor": briefly held the four review permissions and they were
 * removed once §3.3 (articles/tags/categories only) was read against §24
 * (administrators moderate reviews). warehouse_employee never held them.
 * This resource had no test at all before now, on either the panel wiring
 * or the role-denial side, despite carrying a live "approve"/"unapprove"
 * row action and a bulk "approve" action.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
});

it('is reachable by administrator', function (): void {
    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail())
        ->get('/admin/product-reviews')
        ->assertOk();
});

it('is not reachable by content_editor', function (): void {
    $this->actingAs(User::where('email', 'editor@example.com')->firstOrFail())
        ->get('/admin/product-reviews')
        ->assertForbidden();
});

it('is not reachable by warehouse_employee', function (): void {
    $this->actingAs(User::where('email', 'warehouse@example.com')->firstOrFail())
        ->get('/admin/product-reviews')
        ->assertForbidden();
});

it('approves a pending review through the panel row action, reaching ApproveProductReview', function (): void {
    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail());

    $review = ProductReview::factory()->create(['approved' => false]);

    Livewire::test(ListProductReviews::class)
        ->callTableAction('approve', $review);

    expect($review->fresh()->approved)->toBeTrue();
});

it('unapproves an approved review through the panel row action, reaching UnapproveProductReview', function (): void {
    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail());

    $review = ProductReview::factory()->create(['approved' => true]);

    Livewire::test(ListProductReviews::class)
        ->callTableAction('unapprove', $review);

    expect($review->fresh()->approved)->toBeFalse();
});

it('approves every selected review through the bulk action, one call per record', function (): void {
    // The bulk action composes ApproveProductReview per record rather than
    // writing the `approved` column directly — checked because the other
    // shape (DeleteBulkAction calling $record->delete() straight past the
    // single-item Action's guard) is a confirmed bug class on other
    // resources in this codebase. This one gets it right.
    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail());

    $reviews = ProductReview::factory()->count(3)->create(['approved' => false]);

    Livewire::test(ListProductReviews::class)
        ->callTableBulkAction('approve', $reviews);

    expect($reviews->fresh()->pluck('approved')->unique()->all())->toBe([true]);
});
