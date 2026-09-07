<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property OrderStatus $status
 * @property PaymentMethod $payment_method
 * @property-read PaymentStatus $payment_status
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
