# Variation gallery writes — expected behaviour

What happens setting a variation's own image gallery, alone and under
concurrency, and what deleting either end does to it. Product-level images —
upload, the one-main invariant, removal — are in `product.md`'s "Images"
section and are unchanged by any of this.

Facts as of 2026-08-23, measured against the running stack rather than read
off the code. Reasoning is in ADR-0013.

## What a variation gallery is

`product_image_product_variation`: `product_image_id`,
`product_variation_id`, `position`. Composite primary key over the pair, both
foreign keys cascading, an index on `(product_variation_id, position)`.

Images are not owned by the variation. They are the product's own
`product_images` rows, and one image may appear in several variations'
galleries at a different position in each. An image belonging to no gallery is
normal, not orphaned — that is what a size chart or a packaging shot is.

`product_variations.image_id` no longer exists. It was dropped by ADR-0013.

## What enforces any of this

`App\Actions\Catalogue\SetVariationImages`, and nothing else. Direct Eloquent,
a factory, a seeder, or `attach()`/`detach()` on the relation all bypass every
rule below. The composite primary key still refuses a duplicate pairing at the
database whichever path reaches it, and both foreign keys still refuse an id
that does not exist — but without a message naming why.

Nothing else writes the pivot. There is no attach Action, no detach Action, and
no reorder Action: the gallery is a set, and it is written as a set.

## One actor at a time

| Operation | Outcome |
|---|---|
| Gallery set to an ordered list | replaced wholesale; `position` written contiguously from 1 in the order given |
| Gallery set to an empty list | cleared; the variation falls back to the product's main image |
| Same image given twice | collapsed to its first occurrence, keeping the position chosen there |
| An image belonging to another product | refused — `ImageNotOnProductException`, naming the offending ids |
| A variation soft-deleted since the page loaded | refused — `RemovedFromCatalogueException` |
| An image id that does not exist | refused — `ImageNotOnProductException`; a missing row cannot belong to the product |

Positions are contiguous by construction, not by repair. Nothing writes a
partial set, so nothing ever has to close a gap after a removal.

`position` carries no unique constraint. Two rows may share a position if
something outside the Action writes them; `ORDER BY position,
product_image_id` breaks the tie deterministically, so the gallery still
renders in a stable order.

## What deleting either end does

| Event | Effect on the gallery |
|---|---|
| Product image removed (`RemoveProductImage`) | leaves every gallery it was in; the image row and file are deleted |
| Variation soft-deleted (`RemoveProductVariation`) | gallery preserved intact; restoring the variation restores it |
| Variation erased (`ForceDeleteProductVariation`) | pivot rows cascade; the images themselves survive on the product |
| Product erased (`ForceDeleteProduct`) | variations and images both go; pivot rows cascade with them |

Removing an image is **not** refused because a variation shows it. Until
ADR-0013 it was: `product_variations.image_id` was a `NO ACTION` foreign key,
so the database answered error 1451 and `RemoveProductImage` converted that
into `ProductImageInUseException`. Both the column and the exception are gone.

## Concurrency

Locks `products` — the same row, in the same order, as `RemoveProductImage`
and `ForceDeleteProductVariation`. That shared order is what keeps the three
from deadlocking against each other; see `concurrency.md` for the full map.

| Two actors | Outcome |
|---|---|
| Two gallery writes on 1 variation | both succeed; the later one wins wholesale, the earlier one is lost |
| Gallery write, image removal | serialised; if the removal commits first the write refuses that id, otherwise the removal cascades the row away |
| Gallery write, variation soft-delete | serialised; a write landing after the delete refuses with `RemovedFromCatalogueException` |
| Gallery writes on 2 variations of 1 product | serialised by the product lock, both succeed |

The `products` lock is proven rather than assumed. Removing it and re-running
`SetVariationImagesConcurrencyTest` fails the atomicity assertion on 4 of 4
attempts: the surviving gallery contains rows from both submissions, because
`sync()` issues its detach and its attaches as separate statements and two
transactions interleave between them. This is not the outcome
`DeleteProductCategoryConcurrencyTest` reached, where fourteen attempts never
forced the failure — here the window is wide enough to hit every time.

The lost update in the first row is by design, not an unhandled race. A
gallery is a display ordering: the later intention is the current one, and
there is nothing to reconcile between two complete sets. This is the same
outcome `product.md` records for two simultaneous main-image promotions, and
it is deliberately *not* the treatment stock or coupon caps get, where a lost
write is money.

## Which image represents a variation

`App\Support\Resolvers\ResolveVariationImage::current()`, a function rather than an
Action because it writes nothing — the same shape as `ResolveVariationPrice`.

| State | Result |
|---|---|
| The variation has a gallery | its image at the lowest `position` |
| The variation has no gallery | the product's main image |
| The product has no images | `null` |

Nothing is stored. A variation has no "main image" flag of its own; position 1
is the answer, which is why the gallery order is worth writing deliberately.

`current()`'s `null` is legal and deliberate — a caller that needs to *know*
nothing exists (an admin screen, say) gets that as a fact rather than a
disguised default. `ResolveVariationImage::urlOrDefault()` is the other half,
for a caller that only ever wants something to render: same three states, but
the third resolves to `asset('images/default-product.png')`, the static asset
`explanation/filament-resources.md`'s "Static, checked-in assets" documents,
instead of `null`.
