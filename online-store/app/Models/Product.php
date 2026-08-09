<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'slug',
        'sku',
        'short_description',
        'description',
        'is_available',
        'is_featured',
        'available_quantity',
        'reserved_quantity',
        'min_order_quantity',
        'regular_price',
        'rate',
        'weight',
        'dimensions',
        'seo_title',
        'seo_description',
        'product_category_id',
        'brand_id',
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
            'is_available' => 'boolean',
            'is_featured' => 'boolean',
            'regular_price' => 'decimal:2',
            'rate' => 'decimal:2',
            'weight' => 'decimal:2',
            'product_category_id' => 'integer',
            'brand_id' => 'integer',
        ];
    }

    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function productImages(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    public function productVariations(): HasMany
    {
        return $this->hasMany(ProductVariation::class);
    }

    public function productAttributeValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    public function productReviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public function discounts(): BelongsToMany
    {
        return $this->belongsToMany(Discount::class);
    }
}
