<?php

declare(strict_types=1);

use App\Actions\Coupon\DeleteCoupon;
use App\Exceptions\CouponCannotBeDeletedException;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use Database\Seeders\System\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\PermissionRegistrar;

/*
 * EditCoupon's default DeleteAction previously called $record->delete()
 * directly, surfacing coupon_redemptions.coupon_id or carts.coupon_id's
 * foreign keys as an uncaught QueryException (1451) instead of a message
 * naming the dependency. DeleteCoupon is what EditCoupon now routes
 * through instead.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed(PermissionSeeder::class);
});

it('deletes a coupon with no redemptions or carts', function (): void {
    $coupon = Coupon::factory()->create();

    app(DeleteCoupon::class)->handle($coupon, null);

    expect(Coupon::find($coupon->getKey()))->toBeNull();
});

it('refuses a coupon with a redemption and writes nothing', function (): void {
    $coupon = Coupon::factory()->create();
    CouponRedemption::factory()->create(['coupon_id' => $coupon->getKey()]);

    expect(fn () => app(DeleteCoupon::class)->handle($coupon, null))
        ->toThrow(CouponCannotBeDeletedException::class);

    expect(Coupon::find($coupon->getKey()))->not->toBeNull();
});

it('refuses a coupon applied to a cart and writes nothing', function (): void {
    $coupon = Coupon::factory()->create();
    Cart::factory()->create(['coupon_id' => $coupon->getKey()]);

    expect(fn () => app(DeleteCoupon::class)->handle($coupon, null))
        ->toThrow(CouponCannotBeDeletedException::class);

    expect(Coupon::find($coupon->getKey()))->not->toBeNull();
});

it('denies an actor without delete_coupon', function (): void {
    $coupon = Coupon::factory()->create();
    $actor = catalogueActor('update_coupon');

    expect(fn () => app(DeleteCoupon::class)->handle($coupon, $actor))
        ->toThrow(AuthorizationException::class);

    expect(Coupon::find($coupon->getKey()))->not->toBeNull();
});

it('allows an actor holding delete_coupon', function (): void {
    $coupon = Coupon::factory()->create();
    $actor = catalogueActor('delete_coupon');

    app(DeleteCoupon::class)->handle($coupon, $actor);

    expect(Coupon::find($coupon->getKey()))->toBeNull();
});
