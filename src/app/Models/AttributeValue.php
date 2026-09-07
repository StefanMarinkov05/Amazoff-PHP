<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AttributeValueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AttributeValue extends Model
{
    /** @use HasFactory<AttributeValueFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'attribute_id',
        'value',
        'slug',
        'color_hex',
        'sort_order',
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
            'attribute_id' => 'integer',
        ];
    }

    /** Variations distinguished by this value — the variant grid. */
    /** @return BelongsToMany<ProductVariation, $this> */
    public function productVariations(): BelongsToMany
    {
        return $this->belongsToMany(ProductVariation::class);
    }

    /**
     * Products carrying this value descriptively — see
     * `Product::descriptiveAttributeValues()`. Explicit table and keys for
     * the same reason it is: the relation name does not match Laravel's
     * convention for `attribute_value_product`.
     *
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            Product::class,
            'attribute_value_product',
            'attribute_value_id',
            'product_id',
        );
    }

    /** @return BelongsTo<Attribute, $this> */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }
}
