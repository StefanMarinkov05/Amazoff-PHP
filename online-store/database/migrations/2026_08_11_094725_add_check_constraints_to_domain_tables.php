<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Value-level invariants, enforced by the database rather than only by Form
 * Requests.
 *
 * The implementation standards require constraints in the database alongside
 * PHP validation, and the two answer different questions. A Form Request
 * produces a readable message for the person filling in a form; a CHECK
 * constraint holds regardless of which code path wrote the row — a seeder, a
 * queued job, a fixture import, a console command, or an Action that forgot to
 * validate. Under concurrency the application check is advisory and the
 * constraint is not.
 *
 * Only invariants that are true of a single row in isolation appear here.
 * Anything needing another table, another row, or an aggregate is an
 * application invariant and is listed at the bottom of this file.
 *
 * MySQL 8.0.16+ enforces CHECK; earlier versions parsed and ignored it. The
 * project is pinned to MySQL 8, so these bite.
 */
return new class extends Migration
{
    /**
     * Constraint name => the expression that must hold.
     *
     * Names are explicit because MySQL needs one to drop by, and a generated
     * name is not stable across versions.
     *
     * @var array<string, array{table: string, expression: string}>
     */
    private const CHECKS = [
        // Money is never negative. Refunds and discounts are separate columns
        // rather than negative amounts, so there is no legitimate case.
        'chk_products_regular_price_non_negative' => [
            'table' => 'products',
            'expression' => 'regular_price >= 0',
        ],
        'chk_products_discount_price_non_negative' => [
            'table' => 'products',
            'expression' => 'discount_price IS NULL OR discount_price >= 0',
        ],
        // A discount that is not a discount is a data-entry error, and it
        // reaches the customer as a badge showing a negative saving.
        'chk_products_discount_below_regular' => [
            'table' => 'products',
            'expression' => 'discount_price IS NULL OR discount_price < regular_price',
        ],
        // A discount window that ends before it starts never activates, so the
        // row silently does nothing.
        'chk_products_discount_window_ordered' => [
            'table' => 'products',
            'expression' => 'discount_starts_at IS NULL OR discount_ends_at IS NULL OR discount_ends_at > discount_starts_at',
        ],
        // decimal(5,2) already caps the column at 999.99; a VAT rate is a
        // percentage and cannot exceed 100.
        'chk_products_vat_rate_is_a_percentage' => [
            'table' => 'products',
            'expression' => 'vat_rate >= 0 AND vat_rate <= 100',
        ],
        // §10 requires cart quantity never to fall below one, which starts
        // with the product's own minimum being at least one.
        'chk_products_min_order_quantity_positive' => [
            'table' => 'products',
            'expression' => 'min_order_quantity >= 1',
        ],
        'chk_products_weight_non_negative' => [
            'table' => 'products',
            'expression' => 'weight IS NULL OR weight >= 0',
        ],

        // Variation price is nullable because null means "inherit the
        // product's price" — but a present value obeys the same rules.
        'chk_product_variations_price_non_negative' => [
            'table' => 'product_variations',
            'expression' => 'price IS NULL OR price >= 0',
        ],
        'chk_product_variations_discount_price_non_negative' => [
            'table' => 'product_variations',
            'expression' => 'discount_price IS NULL OR discount_price >= 0',
        ],
        'chk_product_variations_discount_below_price' => [
            'table' => 'product_variations',
            'expression' => 'discount_price IS NULL OR price IS NULL OR discount_price < price',
        ],
        'chk_product_variations_weight_non_negative' => [
            'table' => 'product_variations',
            'expression' => 'weight IS NULL OR weight >= 0',
        ],

        // The one that concurrency actually attacks. Two orders reserving the
        // last item race, and without this the loser writes a negative
        // reservation that no report would flag.
        'chk_inventories_current_quantity_non_negative' => [
            'table' => 'inventories',
            'expression' => 'current_quantity >= 0',
        ],
        'chk_inventories_reserved_quantity_non_negative' => [
            'table' => 'inventories',
            'expression' => 'reserved_quantity >= 0',
        ],
        'chk_inventories_sold_quantity_non_negative' => [
            'table' => 'inventories',
            'expression' => 'sold_quantity >= 0',
        ],
        'chk_inventories_returned_quantity_non_negative' => [
            'table' => 'inventories',
            'expression' => 'returned_quantity >= 0',
        ],
        'chk_inventories_damaged_quantity_non_negative' => [
            'table' => 'inventories',
            'expression' => 'damaged_quantity >= 0',
        ],
        // §20: available = current - reserved. Reserving more than exists is
        // the same lost-update bug seen from the other side.
        'chk_inventories_reserved_not_above_current' => [
            'table' => 'inventories',
            'expression' => 'reserved_quantity <= current_quantity',
        ],

        // A movement of zero records nothing. Direction is carried by the sign,
        // so both are permitted — only zero is meaningless.
        'chk_inventory_movements_quantity_non_zero' => [
            'table' => 'inventory_movements',
            'expression' => 'quantity <> 0',
        ],

        'chk_orders_subtotal_non_negative' => [
            'table' => 'orders',
            'expression' => 'subtotal_amount >= 0',
        ],
        'chk_orders_discount_non_negative' => [
            'table' => 'orders',
            'expression' => 'discount_amount >= 0',
        ],
        'chk_orders_shipping_non_negative' => [
            'table' => 'orders',
            'expression' => 'shipping_amount >= 0',
        ],
        'chk_orders_vat_non_negative' => [
            'table' => 'orders',
            'expression' => 'vat_amount >= 0',
        ],
        'chk_orders_total_non_negative' => [
            'table' => 'orders',
            'expression' => 'total_amount >= 0',
        ],
        // A discount larger than the goods is a miscalculated coupon. Shipping
        // is excluded deliberately: §21 discounts apply to the order value.
        'chk_orders_discount_not_above_subtotal' => [
            'table' => 'orders',
            'expression' => 'discount_amount <= subtotal_amount',
        ],

        'chk_order_items_quantity_positive' => [
            'table' => 'order_items',
            'expression' => 'quantity >= 1',
        ],
        'chk_order_items_unit_price_non_negative' => [
            'table' => 'order_items',
            'expression' => 'unit_price >= 0',
        ],
        'chk_order_items_line_total_non_negative' => [
            'table' => 'order_items',
            'expression' => 'line_total >= 0',
        ],
        'chk_order_items_discount_non_negative' => [
            'table' => 'order_items',
            'expression' => 'discount_amount >= 0',
        ],
        'chk_order_items_vat_amount_non_negative' => [
            'table' => 'order_items',
            'expression' => 'vat_amount >= 0',
        ],
        'chk_order_items_vat_rate_is_a_percentage' => [
            'table' => 'order_items',
            'expression' => 'vat_rate >= 0 AND vat_rate <= 100',
        ],

        // §10, stated as a database rule rather than only a cart rule.
        'chk_cart_items_quantity_positive' => [
            'table' => 'cart_items',
            'expression' => 'quantity >= 1',
        ],

        'chk_payments_amount_non_negative' => [
            'table' => 'payments',
            'expression' => 'amount >= 0',
        ],
        'chk_payments_refunded_non_negative' => [
            'table' => 'payments',
            'expression' => 'refunded_amount >= 0',
        ],
        // Refunding more than was captured. §13 distinguishes refunded from
        // partially refunded, and both are bounded by the amount taken.
        'chk_payments_refunded_not_above_amount' => [
            'table' => 'payments',
            'expression' => 'refunded_amount <= amount',
        ],

        'chk_shipments_cod_amount_non_negative' => [
            'table' => 'shipments',
            'expression' => 'cod_amount IS NULL OR cod_amount >= 0',
        ],
        'chk_shipments_weight_non_negative' => [
            'table' => 'shipments',
            'expression' => 'weight IS NULL OR weight >= 0',
        ],

        'chk_coupons_value_non_negative' => [
            'table' => 'coupons',
            'expression' => 'value >= 0',
        ],
        'chk_coupons_max_discount_non_negative' => [
            'table' => 'coupons',
            'expression' => 'max_discount_amount IS NULL OR max_discount_amount >= 0',
        ],
        'chk_coupons_minimum_order_non_negative' => [
            'table' => 'coupons',
            'expression' => 'minimum_order_value IS NULL OR minimum_order_value >= 0',
        ],
        'chk_coupons_window_ordered' => [
            'table' => 'coupons',
            'expression' => 'starts_at IS NULL OR ends_at IS NULL OR ends_at > starts_at',
        ],
        'chk_coupons_total_usage_limit_positive' => [
            'table' => 'coupons',
            'expression' => 'total_usage_limit IS NULL OR total_usage_limit >= 1',
        ],
        'chk_coupons_per_customer_limit_positive' => [
            'table' => 'coupons',
            'expression' => 'usage_limit_per_customer IS NULL OR usage_limit_per_customer >= 1',
        ],
        'chk_coupons_times_used_non_negative' => [
            'table' => 'coupons',
            'expression' => 'times_used >= 0',
        ],
        // A percentage coupon over 100 pays the customer to order.
        'chk_coupons_percentage_within_bounds' => [
            'table' => 'coupons',
            'expression' => "type <> 'percentage' OR value <= 100",
        ],

        // §24 does not state a scale; the storefront and the seed data both
        // use five stars, and tinyInteger would otherwise accept -128..127.
        'chk_product_reviews_rating_within_scale' => [
            'table' => 'product_reviews',
            'expression' => 'rating >= 1 AND rating <= 5',
        ],
    ];

    public function up(): void
    {
        if (! $this->supportsAlterAddConstraint()) {
            return;
        }

        // Grouped into one ALTER per table rather than one per constraint.
        // Each ALTER TABLE is a separate round trip and, on MySQL, may rebuild
        // the table — issuing all 45 individually took five minutes against
        // the Docker MySQL, against roughly twenty seconds batched.
        foreach ($this->groupedByTable() as $table => $clauses) {
            DB::statement(sprintf('ALTER TABLE `%s` %s', $table, implode(', ', $clauses)));
        }
    }

    public function down(): void
    {
        if (! $this->supportsAlterAddConstraint()) {
            return;
        }

        foreach ($this->groupedByTable(dropping: true) as $table => $clauses) {
            DB::statement(sprintf('ALTER TABLE `%s` %s', $table, implode(', ', $clauses)));
        }
    }

    /**
     * The constraint list rearranged into one set of clauses per table.
     *
     * @return array<string, list<string>>
     */
    private function groupedByTable(bool $dropping = false): array
    {
        $grouped = [];

        foreach (self::CHECKS as $name => $check) {
            $grouped[$check['table']][] = $dropping
                ? sprintf('DROP CONSTRAINT `%s`', $name)
                : sprintf('ADD CONSTRAINT `%s` CHECK (%s)', $name, $check['expression']);
        }

        return $grouped;
    }

    /**
     * SQLite accepts CHECK only inside CREATE TABLE — it has no
     * `ALTER TABLE ... ADD CONSTRAINT` at all, and adding one after the fact
     * means rebuilding the table. Pest runs on SQLite in memory, so without
     * this guard every test would fail on the migration rather than on
     * anything it was written to check.
     *
     * The consequence is that these constraints are live in development, in
     * the CI migrate step, and in production, but absent under Pest. Nothing
     * in the test suite exercises them; the factories below are what keeps
     * generated data inside them.
     */
    private function supportsAlterAddConstraint(): bool
    {
        return DB::getDriverName() !== 'sqlite';
    }
};

/*
 * Not expressible as a CHECK, and therefore still application invariants:
 *
 * - orders.total_amount = subtotal - discount + shipping + vat. Arithmetic
 *   across columns is expressible, but the rounding is bcmath's and the
 *   database would disagree at the half-cent. §11 puts the calculation on the
 *   server; this stays there.
 * - SKU unique across products and product_variations together. A UNIQUE
 *   constraint covers one table.
 * - Every product has at least one variation. Minimum cardinality across a
 *   relation has no constraint form.
 * - No two variations of one product carry the same set of attribute values.
 *   Set equality across pivot rows.
 * - Order status transitions follow ADR-0004. A CHECK sees one row, not the
 *   row it replaced.
 * - coupon usage never exceeds total_usage_limit. Compares a column against a
 *   count in another table.
 */
