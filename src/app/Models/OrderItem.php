<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'product_id',
        'product_variation_id',
        'product_name',
        'product_sku',
        'variation_name',
        'quantity',
        'unit_price',
        'line_total',
        'discount_amount',
        'vat_rate',
        'vat_amount',
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
            'order_id' => 'integer',
            'product_id' => 'integer',
            'product_variation_id' => 'integer',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'vat_amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductVariation, $this> */
    public function productVariation(): BelongsTo
    {
        return $this->belongsTo(ProductVariation::class);
    }

    /**
     * Lines from an order belonging to $user that reached a point where
     * §24's "purchaser" is satisfied: the order was delivered, was returned
     * after delivery, or was cancelled after the customer had actually
     * committed to it (anything past `AwaitingPayment`).
     *
     * Excludes a cancellation still in `AwaitingPayment` — the expiry sweep,
     * a failed webhook, and the customer's own Cancel button all produce
     * exactly that shape with no actor, and none of them means anyone
     * received anything. `previous_status` rather than the history row's
     * `user_id`: a staff account can be deleted (`user_id` nulls on delete),
     * which would otherwise silently strip eligibility from orders that
     * account cancelled.
     *
     * @param  Builder<OrderItem>  $query
     */
    #[Scope]
    protected function reviewableBy(Builder $query, User $user): void
    {
        $query
            ->whereHas('order', function (Builder $query) use ($user): Builder {
                /** @var Builder<Order> $query */
                return $query
                    ->where('user_id', $user->getKey())
                    ->whereHas('orderStatusHistories', function (Builder $query): Builder {
                        /** @var Builder<OrderStatusHistory> $query */
                        return $query
                            ->whereIn('new_status', [OrderStatus::Delivered, OrderStatus::Returned])
                            ->orWhere(function (Builder $query): Builder {
                                /** @var Builder<OrderStatusHistory> $query */
                                return $query
                                    ->where('new_status', OrderStatus::Cancelled)
                                    ->where('previous_status', '!=', OrderStatus::AwaitingPayment);
                            });
                    });
            });
    }
}
