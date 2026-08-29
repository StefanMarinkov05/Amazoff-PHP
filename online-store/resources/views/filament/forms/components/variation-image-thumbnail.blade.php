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
    $imageId = $get('image_id');
    $path = $imageId ? \App\Models\ProductImage::query()->whereKey($imageId)->value('path') : null;
    $src = $path ? \Illuminate\Support\Facades\Storage::disk(\App\Models\ProductImage::DISK)->url($path) : asset('images/default-product.png');
@endphp

<img
    src="{{ $src }}"
    alt=""
    class="fi-variation-image-thumbnail"
    style="width: 4rem; height: 4rem; object-fit: cover; border-radius: 0.5rem; border: 1px solid rgb(0 0 0 / 0.1);"
/>
