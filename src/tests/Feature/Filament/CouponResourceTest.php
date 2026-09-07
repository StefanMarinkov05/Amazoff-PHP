<?php

declare(strict_types=1);

use App\Enums\CouponScope;
use App\Filament\Resources\Coupons\Pages\EditCoupon;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\System\PermissionSeeder;
use Database\Seeders\System\RoleSeeder;
use Database\Seeders\System\UserSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * Covers the databaseTransactions() fix in AdminPanelProvider — see
 * reference/actions.md, "Panel-level transactions," and
 * reference/write-rules/coupon.md for what this closes and why.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail());
});

it('has database transactions enabled on the admin panel', function (): void {
    expect(Filament::getPanel('admin')->hasDatabaseTransactions())->toBeTrue();
});

it('wraps a coupon edit and its product-eligibility sync in one transaction', function (): void {
    $kept = Product::factory()->create();
    $removed = Product::factory()->create();
    $added = Product::factory()->create();

    $coupon = Coupon::factory()->create(['scope' => CouponScope::Products]);
    $coupon->products()->sync([$kept->getKey(), $removed->getKey()]);

    // RefreshDatabase already wraps this test in its own transaction, so a
    // bare "level >= 1" assertion would pass whether or not the panel opens
    // a second one. The delta above that baseline is what the panel fix
    // actually contributes — proven by turning it back off below.
    $baseline = DB::transactionLevel();
    $levelsDuringPivotWrite = [];

    // Matches only the detach/sync writes, not the unrelated read-only
    // "left join coupon_product" queries Filament issues elsewhere (select
    // option loading, state hydration) — those run outside this save
    // entirely and would wrongly pull the observed level back to baseline.
    DB::listen(function (QueryExecuted $query) use (&$levelsDuringPivotWrite): void {
        if (preg_match('/^(insert into|delete from) `coupon_product`/i', $query->sql) === 1) {
            $levelsDuringPivotWrite[] = DB::transactionLevel();
        }
    });

    // Detaches $removed, attaches $added — the exact detach-then-sync pair
    // reference/actions.md describes as two separate statements.
    Livewire::test(EditCoupon::class, ['record' => $coupon->getKey()])
        ->fillForm(['products' => [$kept->getKey(), $added->getKey()]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($levelsDuringPivotWrite)->not->toBeEmpty()
        ->and(min($levelsDuringPivotWrite))->toBeGreaterThan($baseline);
});

it('lets the pivot sync commit at the RefreshDatabase baseline when the panel transaction is off', function (): void {
    // Verify-by-deletion companion to the test above: with the panel's own
    // transaction disabled, the same edit's pivot statements run at no more
    // than the ambient test-transaction level, showing the prior assertion
    // is not a tautology of RefreshDatabase's own wrapping.
    Filament::getPanel('admin')->databaseTransactions(false);

    $removed = Product::factory()->create();
    $added = Product::factory()->create();

    $coupon = Coupon::factory()->create(['scope' => CouponScope::Products]);
    $coupon->products()->sync([$removed->getKey()]);

    $baseline = DB::transactionLevel();
    $levelsDuringPivotWrite = [];

    DB::listen(function (QueryExecuted $query) use (&$levelsDuringPivotWrite): void {
        if (preg_match('/^(insert into|delete from) `coupon_product`/i', $query->sql) === 1) {
            $levelsDuringPivotWrite[] = DB::transactionLevel();
        }
    });

    Livewire::test(EditCoupon::class, ['record' => $coupon->getKey()])
        ->fillForm(['products' => [$added->getKey()]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($levelsDuringPivotWrite)->not->toBeEmpty()
        ->and(max($levelsDuringPivotWrite))->toBe($baseline);
});
