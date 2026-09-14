<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ReturnItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One order line, and the quantity of it, inside an `OrderReturn` (ADR-0020).
 *
 * Carries no price snapshot: `order_items` already froze `unit_price` at
 * checkout (§19), and `RefundReturn` computes the refund from there — a second
 * copy would only be a way for the two to disagree.
 */
class ReturnItem extends Model
{
    /** @use HasFactory<ReturnItemFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'return_id',
        'order_item_id',
        'quantity',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'return_id' => 'integer',
            'order_item_id' => 'integer',
            'quantity' => 'integer',
        ];
    }

    /** @return BelongsTo<OrderReturn, $this> */
    public function orderReturn(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class, 'return_id');
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
