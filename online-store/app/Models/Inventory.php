<?php

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
     * @var array
     */
    protected $fillable = [
        'product_id',
        'variation_id',
        'current_quantity',
        'reserved_quantity',
        'available_quantity',
        'sold_quantity',
        'returned_quantity',
        'damaged_quantity',
        'product_variation_id',
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
            'product_id' => 'integer',
            'variation_id' => 'integer',
            'product_variation_id' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariation(): BelongsTo
    {
        return $this->belongsTo(ProductVariation::class);
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(ProductVariation::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
