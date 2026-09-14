<?php

declare(strict_types=1);

use App\Models\Article;
use App\Models\ProductImage;
use App\Support\Resolvers\ResolveArticleImage;
use Illuminate\Support\Facades\Storage;

/*
 * Seeded media and uploaded media live on different disks, and which one a
 * row belongs to is decided by its path prefix rather than by probing both.
 * ADR-0025.
 *
 * The prefix rule is ours, not the framework's: Laravel is perfectly happy to
 * read any path off any disk. What these prove is that the *routing* holds —
 * including the case the whole split exists for, a seeded image still
 * rendering while the uploads volume is empty, which is exactly the state of
 * the first deploy after the volume is mounted.
 */

it('routes a seed-prefixed product image to the seed disk', function (): void {
    $image = new ProductImage(['path' => ProductImage::SEED_DIRECTORY.'/clm0001-main.jpg']);

    expect($image->disk())->toBe(ProductImage::SEED_DISK);
});

it('routes an upload-prefixed product image to the upload disk', function (): void {
    $image = new ProductImage(['path' => ProductImage::DIRECTORY.'/whatever.jpg']);

    expect($image->disk())->toBe(ProductImage::uploadDisk())
        ->and($image->disk())->not->toBe(ProductImage::SEED_DISK);
});

it('renders a seeded product image while the upload disk is completely empty', function (): void {
    // The first-deploy case: an empty uploads volume must not blank the
    // seeded catalogue. Only the upload disk is faked, so the seed disk keeps
    // whatever the real one has — the file below is what makes the assertion
    // about routing rather than about disk contents.
    Storage::fake(ProductImage::uploadDisk());
    Storage::fake(ProductImage::SEED_DISK);

    $path = ProductImage::SEED_DIRECTORY.'/rendered-from-seed.jpg';
    Storage::disk(ProductImage::SEED_DISK)->put($path, 'bytes');

    $image = new ProductImage(['path' => $path]);

    expect($image->servableUrl())
        ->toBe(Storage::disk(ProductImage::SEED_DISK)->url($path))
        ->and($image->servableUrl())->not->toContain('default-product.png');
});

it('falls back to the placeholder for an upload whose file is missing, even when a same-named seed file exists', function (): void {
    // Guards against a "try the other disk too" probe creeping back in: the
    // routing must be exclusive, or an unrelated seed file could stand in for
    // a genuinely missing upload.
    Storage::fake(ProductImage::uploadDisk());
    Storage::fake(ProductImage::SEED_DISK);

    Storage::disk(ProductImage::SEED_DISK)->put(ProductImage::SEED_DIRECTORY.'/collide.jpg', 'bytes');

    $image = new ProductImage(['path' => ProductImage::DIRECTORY.'/collide.jpg']);

    expect($image->servableUrl())->toContain('default-product.png');
});

it('follows MEDIA_DISK when it is repointed', function (): void {
    // The one-variable claim: MEDIA_DISK is both the S3 switch and the
    // rollback lever, so it has to actually reach uploadDisk().
    config(['filesystems.media_disk' => 'some-other-disk']);

    expect(ProductImage::uploadDisk())->toBe('some-other-disk')
        ->and(Article::imageUploadDisk())->toBe('some-other-disk');
});

it('routes a seed-prefixed article cover to the seed disk, and an uploaded one to the upload disk', function (): void {
    $seeded = new Article(['main_image_path' => Article::IMAGE_SEED_DIRECTORY.'/laptop-guide.jpg']);
    $uploaded = new Article(['main_image_path' => Article::IMAGE_DIRECTORY.'/uploaded.jpg']);

    expect($seeded->imageDisk())->toBe(Article::IMAGE_SEED_DISK)
        ->and($uploaded->imageDisk())->toBe(Article::imageUploadDisk());
});

it('resolves a seeded article cover while the upload disk is empty', function (): void {
    Storage::fake(Article::imageUploadDisk());
    Storage::fake(Article::IMAGE_SEED_DISK);

    $path = Article::IMAGE_SEED_DIRECTORY.'/season-cast-iron.jpg';
    Storage::disk(Article::IMAGE_SEED_DISK)->put($path, 'bytes');

    $article = new Article(['main_image_path' => $path]);

    expect(ResolveArticleImage::urlOrNull($article))
        ->toBe(Storage::disk(Article::IMAGE_SEED_DISK)->url($path));
});
