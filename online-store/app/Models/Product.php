<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LengthUnit;
use App\Enums\WeightUnit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property LengthUnit $dimension_display_unit
 * @property WeightUnit $weight_display_unit
 */
class Product extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'product_category_id',
        'brand_id',
        'name',
        'slug',
        'sku',
        'short_description',
        'description',
        'regular_price',
        'discount_price',
        'discount_starts_at',
        'discount_ends_at',
        'vat_rate',
        'min_order_quantity',
        'length_mm',
        'width_mm',
        'height_mm',
        'dimension_display_unit',
        'weight_g',
        'weight_display_unit',
        'is_available',
        'is_featured',
        'seo_title',
        'seo_description',
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
            'product_category_id' => 'integer',
            'brand_id' => 'integer',
            'regular_price' => 'decimal:2',
            'discount_price' => 'decimal:2',
            'discount_starts_at' => 'datetime',
            'discount_ends_at' => 'datetime',
            'vat_rate' => 'decimal:2',
            'length_mm' => 'integer',
            'width_mm' => 'integer',
            'height_mm' => 'integer',
            'dimension_display_unit' => LengthUnit::class,
            'weight_g' => 'integer',
            'weight_display_unit' => WeightUnit::class,
            'is_available' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    public function productImages(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    public function productVariations(): HasMany
    {
        return $this->hasMany(ProductVariation::class);
    }

    public function productSpecifications(): HasMany
    {
        return $this->hasMany(ProductSpecification::class);
    }

    public function productReviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public function wishlistItems(): HasMany
    {
        return $this->hasMany(WishlistItem::class);
    }

    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class);
    }

    public function coupons(): BelongsToMany
    {
        return $this->belongsToMany(Coupon::class);
    }

    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
