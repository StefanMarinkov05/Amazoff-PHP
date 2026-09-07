<?php

declare(strict_types=1);

use App\Enums\AddressType;
use App\Enums\ArticleStatus;
use App\Enums\AttributeInputType;
use App\Enums\CouponScope;
use App\Enums\CouponType;
use App\Enums\DeliveryType;
use App\Enums\InventoryMovementType;
use App\Enums\NewsletterStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ShipmentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Restates every hand-written `enum()` column literal as
     * `EnumClass::values()`, so the DB constraint can no longer drift from
     * the PHP enum it is supposed to mirror.
     *
     * `2026_08_23_120000_add_structured_dimensions_and_weight.php` already
     * does this for `dimension_display_unit`/`weight_display_unit` at
     * creation time. Every column created before that migration hand-typed
     * its list instead, which is what let `orders.status`,
     * `order_status_histories.{previous,new}_status`, `payments.status` and
     * `payment_events.status_{before,after}` all separately restate
     * `OrderStatus`/`PaymentStatus`'s case list — and let `payments.status`
     * and `payment_events.status_{before,after}` fall out of sync with
     * `PaymentStatus` the moment `disputed` was added in application code,
     * caught only by `2026_09_01_100000_add_disputed_to_payment_status_enums`
     * noticing the three call sites by hand. This migration exists so the
     * next enum case doesn't need the same manual audit.
     *
     * ## What this does not change
     *
     * The column's actual allowed values are unchanged — `::values()`
     * produces the exact same literal list MySQL already has, verified
     * against `information_schema.columns` before writing this migration.
     * This is a maintainability fix, not a schema change: the live
     * database's `ENUM(...)` definition is byte-for-byte identical before
     * and after. What changes is that this migration's *source* — and any
     * future one that copies its pattern — reads the list from the enum
     * instead of retyping it, so adding a case to the PHP enum and
     * forgetting the matching `ALTER TABLE` becomes structurally harder:
     * the next migration that touches the column inherits the full set
     * automatically.
     *
     * ## Raw ALTER rather than Doctrine
     *
     * Same reason as `2026_09_01_100000_add_disputed_to_payment_status_enums`:
     * Laravel's schema builder cannot modify an enum's allowed values
     * without `doctrine/dbal`, which this project does not install. MySQL's
     * `MODIFY` replaces the whole column definition, so nullability is
     * restated to match each column's current definition — verified against
     * `information_schema.columns` alongside the value lists, not assumed
     * from the original `CREATE TABLE` migrations (`order_status_histories
     * .previous_status` is nullable; every other column here is not).
     *
     * Append-only, per CLAUDE.md: the original migrations are untouched.
     */
    public function up(): void
    {
        $this->alter('articles', 'status', ArticleStatus::values(), nullable: false);
        $this->alter('attributes', 'input_type', AttributeInputType::values(), nullable: false);
        $this->alter('coupons', 'type', CouponType::values(), nullable: false);
        $this->alter('coupons', 'scope', CouponScope::values(), nullable: false);
        $this->alter('inventory_movements', 'movement_type', InventoryMovementType::values(), nullable: false);
        $this->alter('newsletter_subscribers', 'status', NewsletterStatus::values(), nullable: false);
        $this->alter('order_addresses', 'type', AddressType::values(), nullable: false);
        $this->alter('order_addresses', 'delivery_type', DeliveryType::values(), nullable: false);
        $this->alter('order_status_histories', 'previous_status', OrderStatus::values(), nullable: true);
        $this->alter('order_status_histories', 'new_status', OrderStatus::values(), nullable: false);
        $this->alter('orders', 'status', OrderStatus::values(), nullable: false);
        $this->alter('orders', 'payment_method', PaymentMethod::values(), nullable: false);
        $this->alter('payments', 'method', PaymentMethod::values(), nullable: false);
        $this->alter('shipments', 'status', ShipmentStatus::values(), nullable: false);
        $this->alter('shipment_tracking_events', 'status', ShipmentStatus::values(), nullable: false);

        // payments.status and payment_events.status_before/status_after are
        // deliberately not touched here: they already carry `disputed`
        // (added by 2026_09_01_100000) and PaymentStatus::values() produces
        // the identical list. Nothing to restate; leaving them out of this
        // migration avoids a no-op ALTER on the columns most recently
        // touched by hand, where a mismatch would matter most to notice.
    }

    /**
     * Reverses each column to the literal list its creating migration used,
     * not to "whatever the enum happened to hold" — a rollback restores the
     * schema state that existed before this migration ran, which is a fixed
     * historical fact, independent of the current PHP enum.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE `articles` MODIFY `status` ENUM('draft','published','scheduled','archived') NOT NULL");
        DB::statement("ALTER TABLE `attributes` MODIFY `input_type` ENUM('select','color','text') NOT NULL");
        DB::statement("ALTER TABLE `coupons` MODIFY `type` ENUM('percentage','fixed') NOT NULL");
        DB::statement("ALTER TABLE `coupons` MODIFY `scope` ENUM('entire_order','products','categories') NOT NULL");
        DB::statement("ALTER TABLE `inventory_movements` MODIFY `movement_type` ENUM('initial_stock','new_delivery','order_reservation','completed_sale','reservation_release','customer_return','damaged_product','manual_correction') NOT NULL");
        DB::statement("ALTER TABLE `newsletter_subscribers` MODIFY `status` ENUM('subscribed','unsubscribed') NOT NULL");
        DB::statement("ALTER TABLE `order_addresses` MODIFY `type` ENUM('billing','delivery') NOT NULL");
        DB::statement("ALTER TABLE `order_addresses` MODIFY `delivery_type` ENUM('address','office') NOT NULL");
        DB::statement("ALTER TABLE `order_status_histories` MODIFY `previous_status` ENUM('new','awaiting_payment','paid','confirmed','preparing','ready_for_shipment','shipped','delivered','cancelled','returned','refunded') NULL");
        DB::statement("ALTER TABLE `order_status_histories` MODIFY `new_status` ENUM('new','awaiting_payment','paid','confirmed','preparing','ready_for_shipment','shipped','delivered','cancelled','returned','refunded') NOT NULL");
        DB::statement("ALTER TABLE `orders` MODIFY `status` ENUM('new','awaiting_payment','paid','confirmed','preparing','ready_for_shipment','shipped','delivered','cancelled','returned','refunded') NOT NULL");
        DB::statement("ALTER TABLE `orders` MODIFY `payment_method` ENUM('stripe','cash_on_delivery') NOT NULL");
        DB::statement("ALTER TABLE `payments` MODIFY `method` ENUM('stripe','cash_on_delivery') NOT NULL");
        DB::statement("ALTER TABLE `shipments` MODIFY `status` ENUM('pending','shipped','in_transit','delivered','returned','cancelled') NOT NULL");
        DB::statement("ALTER TABLE `shipment_tracking_events` MODIFY `status` ENUM('pending','shipped','in_transit','delivered','returned','cancelled') NOT NULL");
    }

    /**
     * @param  list<string>  $values
     */
    private function alter(string $table, string $column, array $values, bool $nullable): void
    {
        $list = implode(',', array_map(static fn (string $v): string => "'{$v}'", $values));
        $null = $nullable ? 'NULL' : 'NOT NULL';

        DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` ENUM({$list}) {$null}");
    }
};
