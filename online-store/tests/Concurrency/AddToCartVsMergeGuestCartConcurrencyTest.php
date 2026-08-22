<?php

declare(strict_types=1);

use App\Actions\Cart\AddToCart;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The pairing `AddToCartConcurrencyTest` and `MergeGuestCartConcurrencyTest`
 * each prove against themselves, but never against each other: a customer
 * adding a variation directly while login (and its merge) completes at the
 * same instant. Both classes' docblocks claim this folds the same way — same
 * `UNIQUE(cart_id, product_variation_id)`, same catch-and-retry shape.
 *
 * Same harness as the two siblings: two processes, a barrier, outside
 * RefreshDatabase. See troubleshooting.md, "A concurrency test cannot be
 * written in one process". A second, file-flag rendezvous sits on top of the
 * wall-clock barrier, so process-boot jitter is not the thing deciding who
 * wins.
 *
 * What this file actually proves, measured rather than assumed: the
 * collision is real (caught once with query-level timing instrumentation —
 * `MergeGuestCart`'s insert landing first, `AddToCart` colliding into it and
 * recovering via its own retry) and both Actions fold cleanly when raced
 * six times, repeatedly. What it does **not** prove: `MergeGuestCart`'s own
 * retry, specifically. Across 24 attempts under three different
 * synchronization strategies — deleting either side's retry, with and
 * without the rendezvous — `MergeGuestCart` won every single time in this
 * environment. Its `applyLine()` does no domain validation before its
 * insert; `AddToCart` checks `min_order_quantity` and `available()` first.
 * That is a real, structural difference in code-path length, not noise, and
 * it means this specific pairing (a direct add racing a merge into the same
 * empty cart) never actually exercises `MergeGuestCart` as the loser here —
 * only `AddToCart`. `reference/write-rules/cart.md`'s "Known gaps" records
 * this precisely rather than claiming the pairing is fully verified.
 */

afterEach(function (): void {
    Schema::disableForeignKeyConstraints();

    foreach ([
        'cart_items',
        'carts',
        'inventory_movements',
        'inventories',
        'product_variations',
        'product_images',
        'product_specifications',
        'products',
        'product_categories',
        'brands',
        'coupons',
        'users',
    ] as $table) {
        DB::table($table)->truncate();
    }

    Schema::enableForeignKeyConstraints();
});

/**
 * @return array<int, string> one entry per worker, 'OK' or 'FAILED:<class>'
 */
function raceAddAgainstMerge(int $userCartId, int $guestCartId, int $variationId): array
{
    // `rendezvous` is what makes this pairing meaningful rather than a
    // measurement of boot time: AddToCart and MergeGuestCart do different
    // amounts of work before their own read-then-write, and both
    // deliberately re-read fresh state inside handle(), so that work cannot
    // be hoisted out to equalise the paths. The wall-clock barrier alone
    // leaves boot jitter deciding the winner; this second handshake removes
    // it. See the file docblock for what that did and did not prove.
    return runRaceWorkers([
        [
            'action' => 'add-to-cart',
            'ids' => [$userCartId, $variationId],
            'args' => [1],
            'rendezvous' => 'add',
        ],
        [
            'action' => 'merge-guest-cart',
            'ids' => [$guestCartId, $userCartId],
            'rendezvous' => 'merge',
        ],
    ])->all();
}

it('folds a direct add and a guest-cart merge of the same variation cleanly', function (): void {
    $userCart = Cart::factory()->create(['user_id' => User::factory(), 'coupon_id' => null, 'expires_at' => null]);
    $guestCart = Cart::factory()->create(['user_id' => null, 'coupon_id' => null, 'expires_at' => null]);
    $variation = cartVariation(stock: 10);
    app(AddToCart::class)->handle($guestCart, $variation, 1);

    // One worker adds the variation straight to the user's cart; the other
    // merges a guest cart holding the same variation into it — a customer
    // adding an item in one tab while login completes in another.
    $outputs = collect(raceAddAgainstMerge($userCart->getKey(), $guestCart->getKey(), $variation->getKey()));
    $report = "\nWorker output was:\n".$outputs->implode("\n---\n");

    // Without the retry on whichever side loses the insert race, the loser's
    // UNIQUE(cart_id, product_variation_id) violation surfaces as an
    // uncaught QueryException instead of folding into the winner's row.
    expect($outputs->filter(fn (string $o) => $o === 'OK'))->toHaveCount(
        2,
        'Expected both the add and the merge to succeed. Fewer than two '.
        'usually means the retry was removed on one side and the collision '.
        'surfaced.'.$report,
    );

    $item = CartItem::where('cart_id', $userCart->getKey())->sole();

    expect($item->quantity)->toBe(2, 'One side of the race was lost.'.$report);

    expect(Cart::whereKey($guestCart->getKey())->exists())->toBeFalse(
        'The guest cart should not survive the merge.'.$report,
    );
})->repeat(6);
