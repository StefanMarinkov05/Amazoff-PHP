<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
