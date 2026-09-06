<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WeightUnit;
use Database\Factories\ProductVariationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariation extends Model
{
    /** @use HasFactory<ProductVariationFactory> */
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
        'weight_g',
        'weight_display_unit',
        'length_mm',
        'width_mm',
        'height_mm',
        'is_available',
        'is_default',
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
            'weight_g' => 'integer',
            'weight_display_unit' => WeightUnit::class,
            'length_mm' => 'integer',
            'width_mm' => 'integer',
            'height_mm' => 'integer',
            'is_available' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    /** @return HasOne<Inventory, $this> */
    public function inventory(): HasOne
    {
        return $this->hasOne(Inventory::class);
    }

    /** @return BelongsToMany<AttributeValue, $this> */
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
     *
     * @return BelongsToMany<ProductImage, $this>
     */
    public function images(): BelongsToMany
    {
        return $this->belongsToMany(ProductImage::class)
            ->withPivot('position')
            ->orderByPivot('position')
            ->orderBy('product_images.id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
