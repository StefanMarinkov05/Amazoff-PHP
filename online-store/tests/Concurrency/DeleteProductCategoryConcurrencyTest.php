<?php

declare(strict_types=1);

use App\Exceptions\ProductCategoryCannotBeDeletedException;
use App\Models\ProductCategory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Deleting a category while a subcategory is being created underneath it.
 *
 * Unlike most races in this suite, the two sides are not symmetric. The
 * "create" side is plain Eloquent — the same as a Filament create form for a
 * lookup table, per CLAUDE.md there is no Action wrapping a single-table
 * insert — so if it loses, it loses to `parent_id`'s foreign key directly, as
 * a raw QueryException. The "delete" side goes through
 * `DeleteProductCategory`, so if it loses, it loses to the clean
 * `ProductCategoryCannotBeDeletedException` the lock and re-read produce.
 *
 * `parent_id` (`constrained()`, no cascade) makes exactly one outcome
 * possible regardless of which side wins: the FK cannot be satisfied by both
 * "the parent is gone" and "the child exists" at once. That much is proven —
 * every run, repeated, leaves the database coherent.
 *
 * What is measured rather than assumed: without the second, tighter
 * rendezvous below (on top of the wall-clock barrier), `create` won every
 * time — boot jitter alone was deciding it, the same trap
 * `AddToCartVsMergeGuestCartConcurrencyTest` names. With the rendezvous both
 * sides win a real share (roughly 2:1 toward `delete` across twenty manual
 * runs while this was written), so both failure shapes above are genuinely
 * exercised, repeated over `->repeat(6)`. What deletion-proofing could
 * *not* force, across fourteen attempts with `lockForUpdate()` removed: a
 * run where `delete` loses via a raw `QueryException` instead of the clean
 * exception — the specific window that would need (`create`'s insert
 * committing strictly between `delete`'s unlocked read and its own `DELETE`
 * statement) is narrower than this harness can reliably hit, the same
 * conclusion `write-rules/order.md`'s gap on the unverified deadlock sort
 * already reached for a structurally similar window. The lock stays for the
 * same reason `ReserveStock`'s does regardless: it decides *how* the loser
 * fails, not whether the outcome is coherent, and the FK alone only
 * guarantees the latter.
 */

afterEach(function (): void {
    Schema::disableForeignKeyConstraints();

    foreach (['products', 'product_categories', 'brands'] as $table) {
        DB::table($table)->truncate();
    }

    Schema::enableForeignKeyConstraints();
});

it('leaves the database coherent when a category is deleted and reparented-into at once', function (): void {
    $category = ProductCategory::factory()->create();

    // `rendezvous` on both sides, for the reason this file's docblock
    // measures: without it the create side won every run, boot jitter rather
    // than the lock deciding the outcome.
    $outputs = runRaceWorkers([
        [
            'action' => 'delete-category',
            'ids' => [$category->getKey()],
            'rendezvous' => 'delete',
        ],
        [
            'action' => 'create-child-category',
            'ids' => [$category->getKey()],
            'rendezvous' => 'create',
        ],
    ]);
    $report = raceReport($outputs);

    [$deleteOutput, $createOutput] = $outputs->all();

    // Exactly one side succeeds. Both succeeding is the corruption this test
    // exists to catch — a child row pointing at a category no longer there.
    // Neither succeeding usually means the workers failed to boot.
    expect($outputs->filter(fn (string $o) => $o === 'OK'))->toHaveCount(1, $report);

    if ($deleteOutput === 'OK') {
        // The category won the race and is gone: the create side can only
        // have lost to the foreign key directly, since nothing wrapped it.
        expect($createOutput)->toBe(
            'FAILED:'.QueryException::class,
            'The create side did not fail the way a plain Eloquent insert '.
            'should when its parent is gone mid-request.'.$report,
        );
        expect(ProductCategory::find($category->getKey()))->toBeNull();
    } else {
        // The child landed first: DeleteProductCategory must have re-read
        // under its own lock and refused cleanly, not raced ahead on a
        // stale children() count.
        expect($deleteOutput)->toBe(
            'FAILED:'.ProductCategoryCannotBeDeletedException::class,
            'The delete side did not refuse cleanly. A raw QueryException here '.
            'means its own DELETE hit the foreign key directly instead of its '.
            'children() check catching the concurrent insert first — worth '.
            'checking DeleteProductCategory still lockForUpdate()s the '.
            'category before counting, though this exact failure could not be '.
            "forced deliberately; see this file's own docblock.".$report,
        );
        expect(ProductCategory::find($category->getKey()))->not->toBeNull();
        expect(ProductCategory::where('parent_id', $category->getKey())->count())->toBe(1);
    }
})->repeat(6);
