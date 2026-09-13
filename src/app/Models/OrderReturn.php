<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReturnStatus;
use Database\Factories\OrderReturnFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer return request against one order (ADR-0020).
 *
 * Named `OrderReturn`, not `Return`, because `return` is a PHP reserved word
 * and cannot be a class name — the table is still `returns`. Deliberately not
 * coupled to `orders.status`: see ADR-0020 and `write-rules/returns.md`.
 *
 * @property ReturnStatus $status
 */
class OrderReturn extends Model
{
    /** @use HasFactory<OrderReturnFactory> */
    use HasFactory;

    protected $table = 'returns';

    /** @var list<string> */
    protected $fillable = [
        'order_id',
        'status',
        'reason',
        'resolution_note',
        'requested_at',
        'resolved_at',
        'refunded_amount',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'order_id' => 'integer',
            'status' => ReturnStatus::class,
            'requested_at' => 'datetime',
            'resolved_at' => 'datetime',
            'refunded_amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return HasMany<ReturnItem, $this> */
    public function returnItems(): HasMany
    {
        return $this->hasMany(ReturnItem::class, 'return_id');
    }
}
