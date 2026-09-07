<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Blueprint generates pivot tables as bare foreign-key pairs — no primary key
 * and no unique constraint — so every one of these accepted the same pair
 * twice. A duplicate attachment is not a visible error: it silently doubles a
 * row in every join, so a product would list an attribute twice and a coupon
 * would appear to cover a category twice.
 *
 * A composite primary key rather than a unique index. It expresses the same
 * guarantee, and InnoDB clusters the rows by it, which is the access pattern
 * every one of these tables actually has — always queried by one side of the
 * pair, never by an id of its own. Both columns are already NOT NULL, which
 * a primary key requires.
 *
 * Duplicates are removed before the key is added; the migration fails
 * otherwise. On a database with no duplicates the delete is a no-op.
 */
return new class extends Migration
{
    /**
     * Pivot tables and the column pair that identifies a row in each.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const PIVOTS = [
        'attribute_product' => ['attribute_id', 'product_id'],
        'attribute_value_product_variation' => ['attribute_value_id', 'product_variation_id'],
        'coupon_product' => ['coupon_id', 'product_id'],
        'coupon_product_category' => ['coupon_id', 'product_category_id'],
        'article_tag' => ['article_id', 'tag_id'],
        'article_product' => ['article_id', 'product_id'],
    ];

    public function up(): void
    {
        foreach (self::PIVOTS as $table => [$first, $second]) {
            $this->deleteDuplicates($table, $first, $second);

            Schema::table($table, function (Blueprint $blueprint) use ($first, $second): void {
                $blueprint->primary([$first, $second]);
            });

            // down() leaves a composite index behind so the foreign keys stay
            // covered while the primary key is dropped. Re-applying makes the
            // primary key cover the same columns, so that index is now
            // redundant — without this, a down/up cycle indexes twice.
            if (Schema::hasIndex($table, "{$table}_pair_index")) {
                Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                    $blueprint->dropIndex("{$table}_pair_index");
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::PIVOTS as $table => [$first, $second]) {
            // The replacement index must exist before the primary key is
            // dropped, not after. MySQL refuses to drop an index a foreign key
            // depends on, and the primary key is the only index covering
            // `$first` — so dropping it first fails with errno 1553 rather
            // than leaving the table unindexed.
            Schema::table($table, function (Blueprint $blueprint) use ($first, $second): void {
                $blueprint->index([$first, $second], "{$blueprint->getTable()}_pair_index");
            });

            Schema::table($table, function (Blueprint $blueprint) use ($first, $second): void {
                $blueprint->dropPrimary([$first, $second]);
            });
        }
    }

    /**
     * Keep one row per pair and delete the rest.
     *
     * These tables have no id column, so rows are deduplicated on the pair
     * itself rather than on a surrogate key: read the distinct pairs out,
     * truncate, and write them back.
     */
    private function deleteDuplicates(string $table, string $first, string $second): void
    {
        $distinct = DB::table($table)
            ->select($first, $second)
            ->distinct()
            ->get()
            ->map(fn (object $row): array => [
                $first => $row->{$first},
                $second => $row->{$second},
            ])
            ->all();

        if ($distinct === []) {
            return;
        }

        DB::table($table)->delete();
        DB::table($table)->insert($distinct);
    }
};
