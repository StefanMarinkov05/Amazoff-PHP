<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LengthUnit;
use App\Enums\WeightUnit;
use Database\Factories\ProductFactory;
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
    /** @use HasFactory<ProductFactory> */
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
        'demo_case_order',
        'demo_case_label',
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
            'demo_case_order' => 'integer',
        ];
    }

    /** @return HasMany<ProductImage, $this> */
    public function productImages(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    /** @return HasMany<ProductVariation, $this> */
    public function productVariations(): HasMany
    {
        return $this->hasMany(ProductVariation::class);
    }

    /** @return HasMany<ProductSpecification, $this> */
    public function productSpecifications(): HasMany
    {
        return $this->hasMany(ProductSpecification::class);
    }

    /** @return HasMany<ProductReview, $this> */
    public function productReviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    /**
     * Effective-price observations, for the Omnibus prior-price display
     * (ADR-0021). Append-only; `RecordPriceObservation` writes it.
     *
     * @return HasMany<ProductPriceHistory, $this>
     */
    public function priceHistory(): HasMany
    {
        return $this->hasMany(ProductPriceHistory::class);
    }

    /** @return HasMany<WishlistItem, $this> */
    public function wishlistItems(): HasMany
    {
        return $this->hasMany(WishlistItem::class);
    }

    /**
     * The product's **variation axes** — which attributes it varies by, not
     * what it is. Picking "Size" here is what allows a variation to carry a
     * size; it says nothing about the product itself.
     *
     * Not to be confused with {@see descriptiveAttributeValues()} one method
     * down, whose name is deliberately longer because the two are one letter
     * apart in meaning and a mix-up silently changes which grid a value
     * lands in.
     *
     * @return BelongsToMany<Attribute, $this>
     */
    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class);
    }

    /**
     * Attribute **values** the product carries directly — a fabric
     * composition, a set of scent notes, a certification. Facts true of
     * every variation, which the customer does not choose between and which
     * must never fork the SKU.
     *
     * The counterpart of `ProductVariation::attributeValues()`: same
     * vocabulary, different question. That one answers "what makes this one
     * different"; this one answers "what is this made of". A value belongs
     * in exactly one of the two for a given product — see
     * `explanation/product-variability.md`.
     *
     * Explicit table and keys because the relation name no longer matches
     * Laravel's own convention for `attribute_value_product`; the longer
     * name is worth the three extra arguments.
     *
     * @return BelongsToMany<AttributeValue, $this>
     */
    public function descriptiveAttributeValues(): BelongsToMany
    {
        return $this->belongsToMany(
            AttributeValue::class,
            'attribute_value_product',
            'product_id',
            'attribute_value_id',
        );
    }

    /** @return BelongsToMany<Coupon, $this> */
    public function coupons(): BelongsToMany
    {
        return $this->belongsToMany(Coupon::class);
    }

    /** @return BelongsTo<ProductCategory, $this> */
    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
