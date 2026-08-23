# ADR-0013: How a variation owns images

Status: Accepted
Date: 2026-08-23 · Deciders: Stefan Marinkov

## Context

Until now a variation's entire relationship to an image was
`product_variations.image_id`: a nullable pointer at one row of the product's
own `product_images`. One picture, no order, no second choice. The images
themselves belong to the product — `product_images.product_id` is NOT NULL —
and `AddProductImage`/`SetMainProductImage`/`RemoveProductImage` own the
"exactly one main image" invariant across that set.

That is enough for a catalogue where every variation looks like the product.
It is not enough for the case that motivated this: a product photographed in
several colours, where the red variations need the red photographs and the
blue ones need the blue, in an order a merchandiser chooses. Nothing in the
schema could express "these six pictures, in this sequence, for this
variation".

Two further questions arrived with it, and are answered here because the
answers only make sense against the same model: whether a customer review
attaches to the variation or the product, and whether a variation carries a
description of its own.

## Decision

**A variation's gallery is a many-to-many over the product's existing images.**
`product_image_product_variation` carries `product_image_id`,
`product_variation_id`, and `position`, with a composite primary key over the
pair and both foreign keys cascading. `product_images` is unchanged.

**`product_variations.image_id` is dropped.** With the pivot in place it is a
second answer to one question, and a resolver reading both has to invent a
precedence rule nothing enforces on write.

**One Action owns the whole ordered set.** `SetVariationImages` takes a
variation and an ordered list of image ids and writes the set. There is no
attach, no detach, and no reorder.

**Image lifetime is explicit, never reference-counted.** An image with no pivot
rows is not garbage; it is a product-level image, which is the normal state for
a size chart, a packaging shot, or a lifestyle photograph.

**Reviews stay on the product.** No schema change.

**A variation gets no description of its own.** No schema change.

### Why the pivot rather than a second image table

The rejected alternative was `product_variation_images`, mirroring
`product_images` per variation. It fails on the shape of the actual problem: a
product with five colours and eight sizes has 40 variations and roughly six
photographs per colour. Under a mirror table the same file is uploaded once per
variation. Under the pivot it is one row per pairing over thirty shared assets,
and re-shooting a photograph replaces one file.

The other rejected alternative was extending `product_images` with a nullable
`product_variation_id`, so a row belongs to either owner. That requires
`product_id` to become nullable, which touches every existing query and every
line of the merged, tested triad, and it makes product-level-only images
indistinguishable from unassigned ones.

### Why per-SKU rather than per-attribute-value

The pivot could have pointed at `attribute_values` instead — an image attaches
to "Colour: Red" and a variation's gallery is derived through
`attribute_value_product_variation`. That is Magento's swatch model and it is
genuinely better on write: six rows instead of two hundred and forty, and one
edit when the red photographs change.

It was rejected for three reasons. It makes the attribute system load-bearing
in a way nothing else currently requires. It is the *slower* read — 4 tables
between a variation and its images instead of two. And it has an ordering
problem this model does not: `position` would be scoped per attribute value, so
a variation holding two image-bearing attribute values has two colliding
position sequences and no defined merge order, which would need a new rule
nominating one attribute as authoritative.

The row-count advantage is real and irrelevant at this scale: ten 1000
products is roughly 2.4M pivot rows, which is a small table for InnoDB with a
covering index. The read that actually carries load — the catalogue listing —
touches neither design, because it reads `product_images.is_main` directly.

If the catalogue ever grows wide enough that maintaining per-SKU attachments
hurts the people doing the merchandising, the attribute-value model is the
thing to revisit, and it would supersede this ADR rather than extend it.

### Why the composite primary key

`add_composite_keys_to_pivot_tables` already argued this for the six pivots it
fixed, and the argument transfers unchanged: the table is always read by one
side of the pair and never by an id of its own, both columns are NOT NULL, and
InnoDB clusters the rows by the key. The pair also carries the one uniqueness
guarantee that matters — no image twice in 1 variation's gallery.

An extra index on `(product_variation_id, position)` exists because the primary
key leads with `product_image_id`, and every read this table has is by
variation.

### Why `position` carries no unique constraint

MySQL has no deferrable constraints. `sync()` writes pivot rows one statement
at a time, so swapping two images collides transiently, and there is no way to
defer the check to commit. A uniqueness guarantee here would force every
reorder into a two-phase write — offset the whole set out of range, then write
final values — permanently, to protect a display ordering whose duplicates are
invisible: `ORDER BY position, product_image_id` breaks a tie deterministically
and renders identically.

This diverges from ADR-0005's posture of backing application rules with
database constraints, deliberately. ADR-0005 constrains *data validity* — a
negative quantity, a price outside its column. A duplicate `position` is not
invalid data; it is an ambiguous preference with a defined tie-break.

### Why one Action rather than attach, detach, and reorder

The contested state is the ordered list, not any single membership. One Action
owning it is the same shape `SetMainProductImage` uses for "exactly one main
image" across a product's images.

The consequence is that three anticipated races are not races. Attaching while
another administrator detaches, reordering while another detaches, and two
simultaneous reorders are all the same operation under this shape: two
full-set writes, serialised by the `products` lock, the later one winning
wholesale. It also removes the renumbering problem entirely — positions are
contiguous from 1 by construction, because nothing ever writes a partial set,
so nothing ever has to close a gap.

`SetVariationImages` locks `products`, the same row in the same order as
`RemoveProductImage` and `ForceDeleteProductVariation`, which is what keeps the
three from deadlocking against each other. ADR-0008 has the general rule.

### Why deleting an image is no longer refused

`ProductImageInUseException` and its guard are gone with the column. They
existed to convert one specific error 1451 into an actionable message, and that
1451 is unreachable now: the only remaining reference to `product_images` from
outside its own product is the pivot, which cascades. Removing an image takes
it out of every gallery it was in.

This is a deliberate behaviour change, not an oversight. A gallery membership
is not a dependency the way the old foreign key was — the pairing is gone with
nothing left to repair, and the alternative would force an administrator to
detach an image from 8 variations by hand before deleting it.

### Why reviews stay on the product

`product_reviews` carries `unique(user_id, product_id)`. Moving reviews to the
variation multiplies that constraint by the size of the variation grid and lets
one customer post eight reviews of one shoe in eight sizes. Per-variation
ratings also fragment into single-sample averages, which present noise as
signal.

The information a per-variation review would add is already available without a
schema change: `product_reviews.order_item_id` reaches
`order_items.product_variation_id`, which is exactly how a product-level review
displays the variant that was actually bought.

### Why a variation gets no description

What distinguishes 1 variation from another is already structured — its
attribute values — and renders as such. Prose that a variation needs and its
product does not is usually a sign the variation should be its own product.

There is also a timing reason. Such a column would be translatable text, and
the content translation storage shape is still undecided; adding one before
that decision is made guarantees rework.

## Consequences

- Product images and variation galleries are separately editable, and an
  administrator can produce a variation whose gallery contradicts the product's
  main image. Nothing prevents that, because nothing can tell the difference
  between a mistake and a deliberate merchandising choice.
- An administrator's gallery edit can be silently overwritten by another
  administrator's within one page-load window. Accepted, on the same reasoning
  `write-rules/product.md` already accepts it for main-image promotion.
- Cross-owner integrity — an image of product A on a variation of product B —
  is not expressible as a foreign key, because the constraint spans a column
  neither table holds. It is an application invariant in `SetVariationImages`,
  raising `ImageNotOnProductException`, in the class ADR-0005 records as
  un-constrainable.
- `ProductVariationFactory` no longer attaches an image at all. It used to set
  `image_id` from `ProductImage::factory()`, which created an image belonging
  to a second, unrelated product on every variation it made; `tests/Pest.php`
  worked around it explicitly. The pivot cannot express that mistake.
- One test was deleted rather than rewritten — "keeps the file when the removal
  is refused", which proved `RemoveProductImage` leaves the file on disk when
  it refuses. The Action has no refusal left, so nothing can reach the branch.
  The property is still real and still commented in the Action; what is gone is
  a way to exercise it.
- Filament relation managers cannot nest, so the gallery is edited through a
  row action and modal on `ProductVariationsRelationManager` rather than a
  relation manager of its own. This happens to match the Action: the modal
  submits the whole set.

## Alternatives rejected

- **A mirror `product_variation_images` table.** Duplicates the file per
  variation. Covered above.
- **A nullable `product_variation_id` on `product_images`.** Forces
  `product_id` nullable and rewrites merged, tested code. Covered above.
- **Attribute-value media.** Better on write, worse on read, and premature for
  this catalogue. Covered above, and named as the thing to revisit.
- **Deleting an image once no pivot row references it.** Rejected: it would
  reap exactly the product-level-only images that have no pivot rows by design,
  it races an upload-then-attach flow that has a window with no references, and
  it destroys a file on disk as a side effect of an ordering edit. If orphaned
  assets accumulate, a command that *reports* them is the answer.
- **Flipping `is_available` to false when a variation gains an image.**
  Rejected: `is_available` means "may be sold", which has nothing to do with
  whether a photograph exists. Coupling them makes both harder to reason about
  permanently. "Made unavailable" is the existing soft-delete path, which is
  already non-destructive to a variation's gallery.
- **`products.default_variation_id` or a `main_image` pointer.**
  `product_images.is_main` already answers the second, with an Action owning the
  invariant; a third mechanism would be one too many. The first is genuinely
  absent but creates a new invariant to maintain across soft-delete,
  force-delete, and availability changes, for a presentational default that can
  be derived. Deferred until a merchandiser asks.
