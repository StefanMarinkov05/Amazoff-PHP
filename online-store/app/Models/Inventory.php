<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Inventory extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'product_variation_id',
        'current_quantity',
        'reserved_quantity',
        'sold_quantity',
        'returned_quantity',
        'damaged_quantity',
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
            'product_variation_id' => 'integer',
            'current_quantity' => 'integer',
            'reserved_quantity' => 'integer',
            'sold_quantity' => 'integer',
            'returned_quantity' => 'integer',
            'damaged_quantity' => 'integer',
        ];
    }

    /**
     * §20: available quantity is current minus reserved.
     *
     * Derived rather than stored, per ADR-0002 — a stored copy is a second
     * source of truth that drifts the moment a reservation is written without
     * updating it. The cost is that it cannot be used in a WHERE clause
     * directly; queries filtering on availability compare the two columns.
     */
    public function available(): int
    {
        return $this->current_quantity - $this->reserved_quantity;
    }

    /** @return HasMany<InventoryMovement, $this> */
    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /** @return BelongsTo<ProductVariation, $this> */
    public function productVariation(): BelongsTo
    {
        return $this->belongsTo(ProductVariation::class);
    }
}
