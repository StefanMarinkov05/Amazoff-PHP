<?php

declare(strict_types=1);

use App\Actions\Catalogue\UpdateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Database\QueryException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * Two employees editing one product, each submitting every field because that
 * is what a form posts.
 *
 *   stored   ['original', 100]
 *   A saves  ['new',      100]   — A changed the name, the price rode along
 *   B saves  ['original', 200]   — B changed the price, the name rode along
 *
 * The wanted result is ['new', 200]. Whether that is what happens depends on
 * something neither employee can see, so it is recorded here rather than
 * reasoned about: these tests assert measured behaviour, including the
 * behaviour that is wrong.
 *
 * No lock would help: the two requests are separated by human think time,
 * which no transaction can span. `reference/product-write-rules.md` has the
 * outcome table.
 */

function contestedProduct(): Product
{
    return Product::factory()->create([
        'name' => 'original',
        'regular_price' => '100.00',
        'discount_price' => null,
        'discount_starts_at' => null,
        'discount_ends_at' => null,
        'is_available' => false,
    ]);
}

it('survives two full-payload edits when both actors loaded the row first', function (): void {
    $product = contestedProduct();

    // Both hold their own instance, both read before either wrote.
    $seenByA = Product::findOrFail($product->getKey());
    $seenByB = Product::findOrFail($product->getKey());

    app(UpdateProduct::class)->handle($seenByA, ['name' => 'new', 'regular_price' => '100.00'], null);
    app(UpdateProduct::class)->handle($seenByB, ['name' => 'original', 'regular_price' => '200.00'], null);

    // Eloquent sends only dirty columns, and B's stale name matches B's *own*
    // original, so it never reaches the UPDATE. A property of dirty checking
    // falling the right way, not a guarantee to rely on.
    expect($product->fresh()->name)->toBe('new')
        ->and($product->fresh()->regular_price)->toBe('200.00');
});

it('loses the first edit when the second actor re-reads the row before saving', function (): void {
    $product = contestedProduct();

    app(UpdateProduct::class)->handle(
        Product::findOrFail($product->getKey()),
        ['name' => 'new', 'regular_price' => '100.00'],
        null,
    );

    // B's instance is re-read after A committed, so its originals are current
    // while its payload is not.
    app(UpdateProduct::class)->handle(
        Product::findOrFail($product->getKey()),
        ['name' => 'original', 'regular_price' => '200.00'],
        null,
    );

    // A's rename is gone. Same dirty checking, opposite outcome: measured
    // against 'new', B's stale 'original' *is* a change, so it is written.
    // This assertion documents a defect rather than a guarantee — it flips to
    // 'new' the day optimistic concurrency is added, which is the intended
    // signal to update it.
    expect($product->fresh()->name)->toBe('original')
        ->and($product->fresh()->regular_price)->toBe('200.00');
});

/*
 * The proposed fix: submit only the fields the actor actually changed and let
 * every other column keep whatever the database currently holds. These three
 * tests bound what that buys.
 */

it('keeps both edits when each actor submits only the field they changed', function (): void {
    $product = contestedProduct();

    app(UpdateProduct::class)->handle(Product::findOrFail($product->getKey()), ['name' => 'new'], null);
    app(UpdateProduct::class)->handle(Product::findOrFail($product->getKey()), ['regular_price' => '200.00'], null);

    // Both survive, even though B re-read the row — the panel case that loses
    // the edit above. A column absent from the payload is never filled, so it
    // is never dirty, so it never reaches the UPDATE.
    expect($product->fresh()->name)->toBe('new')
        ->and($product->fresh()->regular_price)->toBe('200.00');
});

it('still loses an edit when both actors change the same field', function (): void {
    $product = contestedProduct();

    app(UpdateProduct::class)->handle(Product::findOrFail($product->getKey()), ['regular_price' => '250.00'], null);
    app(UpdateProduct::class)->handle(Product::findOrFail($product->getKey()), ['regular_price' => '200.00'], null);

    // Partial updates make *disjoint* edits safe and do nothing for
    // overlapping ones. A's 250.00 is gone and neither actor is told.
    expect($product->fresh()->regular_price)->toBe('200.00');
});

it('can break a cross-field invariant that a full payload would have satisfied', function (): void {
    $product = contestedProduct();

    // A drops the list price. B loaded the page while it was still 100.00.
    app(UpdateProduct::class)->handle(Product::findOrFail($product->getKey()), ['regular_price' => '50.00'], null);

    // B sets a discount that was below the price B saw and is above the price
    // that is now stored. chk_products_discount_below_regular rejects it.
    expect(fn () => app(UpdateProduct::class)->handle(
        Product::findOrFail($product->getKey()),
        ['discount_price' => '80.00'],
        null,
    ))->toThrow(QueryException::class);

    // Partial updates move the failure rather than removing it: a full
    // payload would have carried regular_price 100.00 along, satisfied the
    // constraint, and silently undone A's price cut instead. ADR-0005's
    // constraint is what turns silent corruption into a loud failure here —
    // it is not the partial update that saved anything.
    expect($product->fresh()->regular_price)->toBe('50.00')
        ->and($product->fresh()->discount_price)->toBeNull();
});

/*
 * The publish-versus-remove race is not here. It needs two connections, and a
 * single-process test can only fake it by injecting a delete into the same
 * transaction — which a row lock cannot prevent and is not supposed to, so
 * such a test passes with the lock and without it. It lives in
 * `tests/Concurrency/PublishProductConcurrencyTest.php` instead.
 */

it('loses the first edit through the admin panel', function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail());

    $product = contestedProduct();

    // B opens the edit page. Its form now holds name 'original', price 100.
    $pageB = Livewire::test(EditProduct::class, ['record' => $product->getKey()]);

    // A saves a rename from another session, invisibly to that page.
    Product::whereKey($product->getKey())->update(['name' => 'new']);

    // B edits only the price and saves. B never touched the name field.
    $pageB->fillForm(['regular_price' => '200.00'])
        ->call('save')
        ->assertHasNoFormErrors();

    // Livewire re-resolves the record from the database when the request
    // hydrates, so the panel is always the re-read case above — never the
    // forgiving one. This is why the defect is reachable in the product the
    // customer sees and not in a naive two-instance test.
    expect($product->fresh()->name)->toBe('original')
        ->and($product->fresh()->regular_price)->toBe('200.00');
});
