<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Currency;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Carbon\CarbonInterface;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property OrderStatus $status
 * @property PaymentMethod $payment_method
 * @property-read PaymentStatus $payment_status
 * @property Currency $currency
 * @property Carbon|null $anonymized_at
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'carrier_id',
        'cart_id',
        'serial_number',
        'email',
        'phone',
        'first_name',
        'last_name',
        'status',
        'payment_method',
        'currency',
        'subtotal_amount',
        'discount_amount',
        'shipping_amount',
        'vat_amount',
        'total_amount',
        'customer_note',
        'internal_note',
        'invoice_required',
        'invoice_company',
        'invoice_vat_number',
        'invoice_eik',
        'anonymized_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'user_id' => 'integer',
            'carrier_id' => 'integer',
            'cart_id' => 'integer',
            'status' => OrderStatus::class,
            'payment_method' => PaymentMethod::class,
            // Cast to the enum (ADR-0004) so the storefront and the panel get
            // a symbol and a label, not a bare "EUR" string.
            'currency' => Currency::class,
            'subtotal_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'shipping_amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'invoice_required' => 'boolean',
            'anonymized_at' => 'datetime',
        ];
    }

    /** @return HasMany<OrderItem, $this> */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<OrderStatusHistory, $this> */
    public function orderStatusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    /** @return HasMany<OrderAddress, $this> */
    public function orderAddresses(): HasMany
    {
        return $this->hasMany(OrderAddress::class);
    }

    /** @return HasMany<CouponRedemption, $this> */
    public function couponRedemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    /** @return HasMany<OrderReturn, $this> */
    public function returns(): HasMany
    {
        return $this->hasMany(OrderReturn::class);
    }

    /**
     * When this order reached `Delivered`, or null if it has not.
     *
     * Read from the `order_status_histories` row for the `Delivered`
     * transition rather than a column — `UNIQUE(order_id, new_status)` means
     * there is at most one, and `OrderStatus`'s graph is acyclic so it cannot
     * be re-entered. The 14-day withdrawal window (`RequestReturn`,
     * `config('returns.withdrawal_days')`) and the storefront's
     * return-eligibility display both start from this, so the rule lives once
     * here. ADR-0020.
     */
    public function deliveredAt(): ?CarbonInterface
    {
        $row = $this->orderStatusHistories()
            ->where('new_status', OrderStatus::Delivered)
            ->first();

        return $row?->created_at;
    }

    /**
     * When this order entered `AwaitingPayment`, or null if it never has.
     *
     * The card path's clock, read by `ExpireUnpaidOrders` (ADR-0022) to
     * decide whether an unpaid order has outlived
     * `config('orders.unpaid_ttl_minutes')`. Not `created_at`: the two
     * differ by however long the customer spent on the address step, and it
     * is reaching the payment step that starts the timer.
     *
     * Same shape and same reasoning as `deliveredAt()` above —
     * `UNIQUE(order_id, new_status)` guarantees at most one such row, and
     * `OrderStatus`'s acyclic graph means it cannot be re-entered, so there
     * is no ambiguity about which visit is meant.
     */
    public function awaitingPaymentSince(): ?CarbonInterface
    {
        $row = $this->orderStatusHistories()
            ->where('new_status', OrderStatus::AwaitingPayment)
            ->first();

        return $row?->created_at;
    }

    /** @return HasOne<Shipment, $this> */
    public function shipment(): HasOne
    {
        return $this->hasOne(Shipment::class);
    }

    /** @return HasOne<Payment, $this> */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    /**
     * The order's payment status, derived from `payments` rather than stored.
     *
     * This was a column until 2026-08-24, written once by `CreateOrder` and
     * never again while `payments.status` moved independently — so it read
     * `pending` on an order whose payment was already `paid`, and that was
     * the value the panel displayed and filtered on. §20 forbids storing
     * `inventories.available()` for the same reason; a derivable fact in a
     * column goes wrong the first time something writes one side and not the
     * other.
     *
     * `Pending` when no payment row exists yet: `CreateOrder` does not open
     * one (see `reference/write-rules/order.md` known gap 5), so "no payment
     * recorded" and "payment not yet processed" are the same state from an
     * order's point of view, and both are what `Pending` already meant.
     *
     * **Eager-load `payment` before reading this in a list.** It is a
     * relation access, so N rows without `with('payment')` is N queries —
     * `OrdersTable` does that in its `modifyQueryUsing()`.
     *
     * Declared as an Eloquent accessor rather than a plain method so that
     * `$order->payment_status` keeps working unchanged for the panel and for
     * anything that already read it as an attribute. The annotation on this
     * class marks it `@property-read`: nothing may assign it any more.
     *
     * @return Attribute<PaymentStatus, never>
     */
    protected function paymentStatus(): Attribute
    {
        return Attribute::get(function (): PaymentStatus {
            /** @var Payment|null $payment */
            $payment = $this->payment;

            // Explicit null check rather than `?->status ?? Pending`:
            // Larastan resolves the relation property as non-nullable and
            // reports the nullsafe as redundant, but it genuinely is null
            // for an order with no payment row — which is the common case,
            // since CreateOrder does not open one.
            if ($payment === null) {
                return PaymentStatus::Pending;
            }

            return $payment->status;
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Carrier, $this> */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    /**
     * No database foreign key — `cart_id` is a plain `UNIQUE` column, not a
     * constrained one (see the migration's docblock). The relation still
     * works; Eloquent needs no FK to join on a column.
     *
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }
}
