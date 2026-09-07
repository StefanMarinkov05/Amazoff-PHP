<?php

declare(strict_types=1);

use App\Models\Coupon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('drops the times_used column', function (): void {
    expect(Schema::hasColumn('coupons', 'times_used'))->toBeFalse();
});

it('leaves the remaining coupon constraints intact', function (): void {
    // A row obeying every other CHECK still inserts cleanly — the migration
    // touched only times_used and its own constraint.
    $coupon = Coupon::factory()->create([
        'value' => '10.00',
        'max_discount_amount' => '5.00',
        'minimum_order_value' => '20.00',
        'total_usage_limit' => 5,
        'usage_limit_per_customer' => 1,
    ]);

    expect($coupon->exists)->toBeTrue();

    // The window-ordering constraint still bites.
    expect(fn () => DB::table('coupons')->insert(array_merge(
        Coupon::factory()->make([
            'starts_at' => now(),
            'ends_at' => now()->subDay(),
        ])->toArray(),
        ['code' => 'BADWINDOW'],
    )))->toThrow(QueryException::class);
})->skip(fn () => DB::getDriverName() === 'sqlite', 'CHECK constraints are not enforced under Pest\'s SQLite connection.');
