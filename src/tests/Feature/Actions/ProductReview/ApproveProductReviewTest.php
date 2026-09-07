<?php

declare(strict_types=1);

use App\Actions\ProductReview\ApproveProductReview;
use App\Actions\ProductReview\UnapproveProductReview;
use App\Models\ProductReview;
use App\Models\User;
use Database\Seeders\System\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\PermissionRegistrar;

/*
 * §24: approval is a moderation step, distinct from update_product_review,
 * and the catalogue assigns it to administrators only (permissions.md,
 * "content_editor" — briefly held it and it was removed once §3.3 was read
 * against §24). Both Actions share one ability (ProductReviewPolicy::approve),
 * which is why they are tested together here — until now, neither had a
 * test at all, despite both having a live panel action
 * (ProductReviewsTable, including a bulk "approve" that composes
 * ApproveProductReview per record rather than writing the column directly).
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed(PermissionSeeder::class);
});

it('approves a pending review', function (): void {
    $review = ProductReview::factory()->create(['approved' => false]);

    $result = app(ApproveProductReview::class)->handle($review);

    expect($result->approved)->toBeTrue()
        ->and($review->fresh()->approved)->toBeTrue();
});

it('unapproves an approved review', function (): void {
    $review = ProductReview::factory()->create(['approved' => true]);

    $result = app(UnapproveProductReview::class)->handle($review);

    expect($result->approved)->toBeFalse()
        ->and($review->fresh()->approved)->toBeFalse();
});

it('allows approval from an actor holding approve_product_review', function (): void {
    $moderator = User::factory()->create();
    $moderator->givePermissionTo('approve_product_review');

    $review = ProductReview::factory()->create(['approved' => false]);

    $result = app(ApproveProductReview::class)->handle($review, $moderator);

    expect($result->approved)->toBeTrue();
});

it('refuses approval from an actor holding no approve_product_review permission', function (): void {
    $customer = User::factory()->create();
    $review = ProductReview::factory()->create(['approved' => false]);

    expect(fn () => app(ApproveProductReview::class)->handle($review, $customer))
        ->toThrow(AuthorizationException::class);

    expect($review->fresh()->approved)->toBeFalse();
});

it('refuses unapproval from an actor holding no approve_product_review permission', function (): void {
    $customer = User::factory()->create();
    $review = ProductReview::factory()->create(['approved' => true]);

    expect(fn () => app(UnapproveProductReview::class)->handle($review, $customer))
        ->toThrow(AuthorizationException::class);

    expect($review->fresh()->approved)->toBeTrue();
});

it('performs no authorization check when no actor is passed', function (): void {
    // System-driven calls (a seeder, a queued job) pass no actor, the same
    // convention as SetMainProductImage and its siblings.
    $review = ProductReview::factory()->create(['approved' => false]);

    $result = app(ApproveProductReview::class)->handle($review, null);

    expect($result->approved)->toBeTrue();
});
