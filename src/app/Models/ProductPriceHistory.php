<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProductPriceHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One observation of a product's effective selling price at a moment in time
 * (Omnibus prior-price display, ADR-0021).
 *
 * Append-only. Nothing updates or deletes a row except the `product_id`
 * cascade when a product is force-deleted.
 *
 * @property numeric-string $price
 * @property Carbon|null $recorded_at
 */
class ProductPriceHistory extends Model
{
    /** @use HasFactory<ProductPriceHistoryFactory> */
    use HasFactory;

    protected $table = 'product_price_history';

    /** @var list<string> */
    protected $fillable = [
        'product_id',
        'price',
        'recorded_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'product_id' => 'integer',
            'price' => 'decimal:2',
            'recorded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
