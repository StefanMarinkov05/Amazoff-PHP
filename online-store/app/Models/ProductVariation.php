<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariation extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'sku',
        'price',
        'discount_price',
        'weight',
        'is_available',
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
            'price' => 'decimal:2',
            'discount_price' => 'decimal:2',
            'weight' => 'decimal:2',
            'is_available' => 'boolean',
        ];
    }

    public function inventory(): HasOne
    {
        return $this->hasOne(Inventory::class);
    }

    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(AttributeValue::class);
    }

    /**
     * The variation's own gallery, ordered.
     *
     * Shares `product_images` with the product rather than owning rows of its
     * own, so one photograph can represent several variations at a different
     * position in each. `SetVariationImages` owns the whole ordered set;
     * nothing else writes the pivot.
     *
     * The `id` tie-break is load-bearing, not decoration: `position` carries
     * no uniqueness constraint, so two rows may share a position and the order
     * would otherwise be whatever InnoDB returned. ADR-0013.
     */
    public function images(): BelongsToMany
    {
        return $this->belongsToMany(ProductImage::class)
            ->withPivot('position')
            ->orderByPivot('position')
            ->orderBy('product_images.id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
