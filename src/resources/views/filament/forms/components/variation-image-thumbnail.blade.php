{{--
    One Repeater row's live preview, for ProductVariationsRelationManager's
    gallery modal. Reads the sibling `image_id` Select's current state rather
    than a stored model, since a row the admin just added has no
    product_image_product_variation row yet.

    Falls back to the project-wide placeholder — public/images/default-product.png
    — when nothing is selected, so an empty row still renders a thumbnail-sized
    box instead of a gap that makes the drag target unclear.
--}}
@php
    // The model, not just its `path` column: `servableUrl()` is what knows
    // which disk a row lives on (seeded vs uploaded, ADR-0025) and it also
    // falls back to the placeholder when the file behind the row is missing —
    // a case the raw Storage::url() call this replaced rendered as a broken
    // image.
    $imageId = $get('image_id');
    $image = $imageId ? \App\Models\ProductImage::query()->whereKey($imageId)->first() : null;
    $src = $image?->servableUrl() ?? asset('images/default-product.png');
@endphp

<img
    src="{{ $src }}"
    alt=""
    class="fi-variation-image-thumbnail"
    style="width: 4rem; height: 4rem; object-fit: cover; border-radius: 0.5rem; border: 1px solid rgb(0 0 0 / 0.1);"
/>
