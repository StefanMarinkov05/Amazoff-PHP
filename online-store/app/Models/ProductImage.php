<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProductImage extends Model
{
    /**
     * Uploads live on the `public` disk, served through the `storage` symlink.
     * Named here so the Filament upload field and RemoveProductImage cannot
     * drift onto different disks. Production swaps this for object storage.
     */
    public const DISK = 'public';

    /** Directory within the disk. */
    public const DIRECTORY = 'product-images';

    /**
     * MIME types accepted at upload — no SVG, no arbitrary "image/*". §37
     * standard 18 requires size, type, and storage checks; a raster allow-list
     * plus `->image()`'s own MIME sniff is what closes it here.
     */
    public const ACCEPTED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** In kilobytes, `FileUpload::maxSize()`'s unit. */
    public const MAX_SIZE_KB = 4096;

    /**
     * Below this, a "photograph" is a thumbnail nobody can zoom into and the
     * main-image slot on a product page looks broken. Not a business rule —
     * a floor against an accidental upload, the same role `maxSize` plays on
     * the other end.
     */
    public const MIN_WIDTH_PX = 400;

    public const MIN_HEIGHT_PX = 400;

    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'path',
        'alt_text',
        'is_main',
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
            'product_id' => 'integer',
            'is_main' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The variations showing this image in their own gallery.
     *
     * The only reference to a product image outside its own product since
     * `product_variations.image_id` was dropped, and it cascades — so removing
     * an image can no longer fail on a foreign key. ADR-0013.
     */
    public function productVariations(): BelongsToMany
    {
        return $this->belongsToMany(ProductVariation::class);
    }
}
