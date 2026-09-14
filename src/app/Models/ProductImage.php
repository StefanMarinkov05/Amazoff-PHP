<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProductImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    /**
     * Seeded demo photos, committed to the repository and therefore baked
     * into the container image. Distinct from the upload disk because
     * Railway's filesystem is ephemeral: the seed disk must survive a
     * redeploy by shipping *inside* the image, while uploads survive by
     * living on a mounted volume. ADR-0025.
     */
    public const SEED_DISK = 'public';

    /**
     * Directory within `SEED_DISK`. Also the prefix `disk()` routes on, which
     * is why it is a constant rather than a convention spelled out in the
     * fixtures alone — `database/fixtures/demo/*.json` writes paths with it.
     */
    public const SEED_DIRECTORY = 'demo';

    /** Directory within the upload disk. Never holds seed content. */
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

    /** @use HasFactory<ProductImageFactory> */
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

    /** @return BelongsTo<Product, $this> */
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
     *
     * @return BelongsToMany<ProductVariation, $this>
     */
    public function productVariations(): BelongsToMany
    {
        return $this->belongsToMany(ProductVariation::class);
    }

    /**
     * A servable URL for this row, falling back to the shipped placeholder
     * when the file the row points at does not actually exist on disk.
     *
     * `product_images.path` having a row is not the same guarantee as the
     * file being there — an admin action that deletes/moves the file
     * without deleting the row (a manual disk change, a failed upload that
     * still wrote its row, a restored database against a fresh disk) leaves
     * exactly this state, and it is not hypothetical: confirmed live by
     * creating a row with a path that was never a real file and watching
     * every one of the catalogue card, product gallery, and cart all
     * render the browser's native broken-image icon — `ResolveVariationImage
     * ::urlOrDefault()`'s "no image row" fallback never triggers, because a
     * row does exist; it is simply pointing at nothing.
     *
     * `Storage::exists()` is one disk check per image render — still exactly
     * one after the seed/upload split, because `disk()` resolves by path
     * prefix rather than probing both disks. Accepted here rather than
     * optimised away, because a broken image is a worse failure mode than one
     * stat call. Under a remote `MEDIA_DISK` (S3) that stat becomes a network
     * round-trip and must be revisited — see `explanation/storefront-pages.md`
     * and ADR-0025.
     */
    public function servableUrl(): string
    {
        $disk = $this->disk();

        if (! Storage::disk($disk)->exists($this->path)) {
            return asset('images/default-product.png');
        }

        return Storage::disk($disk)->url($this->path);
    }

    /**
     * Which disk this row's file lives on, decided by its path prefix.
     *
     * Seed content is written with a `demo/` prefix by the demo fixtures and
     * `demo:fetch-images`; an upload is written under `DIRECTORY`. The prefix
     * is therefore already an unambiguous record of origin, which is why this
     * resolves rather than probes: probing both disks would double the
     * `exists()` call in `servableUrl()` (a network round-trip each under S3)
     * and would resolve non-deterministically if the same filename existed on
     * both. ADR-0025.
     */
    public function disk(): string
    {
        return str_starts_with((string) $this->path, self::SEED_DIRECTORY.'/')
            ? self::SEED_DISK
            : self::uploadDisk();
    }

    /**
     * Where a new upload is written. A method, not a constant, because it
     * reads config — `MEDIA_DISK` is what switches the whole application to
     * object storage, and is also the one-variable rollback to the previous
     * single-disk behaviour (`MEDIA_DISK=public`).
     */
    public static function uploadDisk(): string
    {
        /** @var string $disk */
        $disk = config('filesystems.media_disk');

        return $disk;
    }
}
