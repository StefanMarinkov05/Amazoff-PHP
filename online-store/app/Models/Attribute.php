<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttributeInputType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attribute extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'input_type',
        'is_filterable',
        'is_variation_only',
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
            'input_type' => AttributeInputType::class,
            'is_filterable' => 'boolean',
            'is_variation_only' => 'boolean',
        ];
    }

    public function attributeValues(): HasMany
    {
        return $this->hasMany(AttributeValue::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }

    /**
     * The categories this attribute is allowed for. Empty means unrestricted
     * — allowed everywhere — not "allowed nowhere"; see
     * App\Support\ResolveAllowedAttributes, the only place this relation's
     * emptiness is given that meaning.
     */
    public function productCategories(): BelongsToMany
    {
        return $this->belongsToMany(ProductCategory::class);
    }
}
