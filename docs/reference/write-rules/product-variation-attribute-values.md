# Variation attribute-value writes — expected behaviour

What happens setting a variation's own attribute-value combination — "what
makes this one different?", `explanation/product-variability.md`'s first
question — alone and under concurrency, and what deleting or changing either
end does to it. The product's own variation axes (`attributes`, the
`Product` ↔ `Attribute` pivot `ProductForm` labels "Variation axes") are a
separate relationship and are unchanged by any of this.

Facts as of 2026-08-30, measured against the running stack rather than read
off the code. Reasoning is in ADR-0005 (the two rules here are both named
there as outside the database's reach) and ADR-0008 (the lock).

## What a combination is

`attribute_value_product_variation`: `attribute_value_id`,
`product_variation_id`. Composite primary key over the pair, both foreign
keys cascading.

A variation's combination is not owned by the variation the way a column
would be — it is a set of rows pointing at the product's own `attribute_values`
(themselves scoped to an `attribute`), and the combination is only ever
written or read as that whole set. There is no attach Action, no detach
Action: the combination is a set, and it is written as a set, the same shape
`SetVariationImages` gives the gallery.

## What enforces any of this

`App\Actions\Catalogue\SetVariationAttributeValues`, and nothing else. Direct
Eloquent, a factory, a seeder, or `attach()`/`detach()` on the relation all
bypass every rule below. The composite primary key still refuses a duplicate
pairing at the database whichever path reaches it, and both foreign keys
still refuse an id that does not exist — but without a message naming why,
and neither refuses the two rules that matter most here, since both are
application invariants ADR-0005 names as outside a `CHECK` constraint's
reach: a value's own attribute has to be one the product actually uses, and
no two live variations of one product may carry the identical set.

`AddProductVariation` composes this Action when the caller supplies
`attribute_value_ids`, so a variation can be given its combination in the
same call that gives it a SKU and a stock row.

## One actor at a time

| Operation | Outcome |
|---|---|
| Combination set to a list of values | replaces the whole set |
| Combination set to an empty list | cleared |
| Same value given twice | collapsed silently |
| A value belonging to an attribute the product does not use | refused — `AttributeValueNotOnProductException`, naming the offending ids |
| Two values for the same attribute | refused — `DuplicateVariationAttributeException`, naming the attribute |
| The exact same set another live variation of the product already carries | refused — `DuplicateVariationCombinationException`, naming the sibling |
| The same set the variation already carries, resubmitted unchanged | allowed — the uniqueness check excludes the variation being written from its own comparison |
| A variation soft-deleted since the page loaded | refused — `RemovedFromCatalogueException` |

Unlike the gallery, there is no ordering to preserve — the combination is an
unordered set, so nothing here writes a `position` column.

## What deleting or changing either end does

| Event | Effect on the combination |
|---|---|
| A sibling variation holding a combination is soft-deleted (`RemoveProductVariation`) | that combination becomes available again — a removed variation has already left the catalogue, so its old combination is not a permanent reservation |
| A variation is erased (`ForceDeleteProductVariation`) | pivot rows cascade; the attribute values themselves survive on the product |
| A product's own variation axes are narrowed (`UpdateProduct`, dropping an `Attribute`) | not retroactive — an existing variation keeps whatever value it already had from the dropped axis until `SetVariationAttributeValues` next runs against it, at which point that value is now foreign and the write is refused |
| An `AttributeValue` row is deleted outright | pivot rows cascade; no Action in this codebase deletes one today |

The third row is a real gap worth naming rather than hiding: a product can
end up, for a while, with a live variation whose stored combination
references an attribute the product's own "Variation axes" field no longer
lists. Nothing surfaces this to an administrator proactively — it only
becomes visible the next time that variation's own combination is edited and
refused.

## Concurrency

Locks `products` — the same row, in the same order, as `SetVariationImages`,
`RemoveProductImage`, and `ForceDeleteProductVariation`. That shared order is
what keeps this from deadlocking against any of them; see `concurrency.md`
for the full map.

| Two actors | Outcome |
|---|---|
| Two combination writes on 1 variation | both succeed; the later one wins wholesale, the earlier one is lost |
| Combination write, variation soft-delete | serialised; a write landing after the delete refuses with `RemovedFromCatalogueException` |
| Combination writes on 2 variations of 1 product | serialised by the product lock, both succeed provided the two combinations differ |

The `products` lock is proven rather than assumed. Removing it and re-running
`SetVariationAttributeValuesConcurrencyTest` fails the atomicity assertion on
4 of 4 attempts: the surviving combination contains values from both
submissions, because `sync()` issues its detach and its attaches as separate
statements and two transactions interleave between them — the same failure
mode `SetVariationImagesConcurrencyTest` demonstrates for the gallery.

The lost update in the first row is by design, not an unhandled race, for
the same reason `product-variation-images.md` accepts it for the gallery: a
combination write has nothing to reconcile between two complete sets, and is
not the treatment stock or coupon caps get, where a lost write is money.

## The admin form

`ProductVariationsRelationManager`'s own `attribute_value_ids` field, and the
create-time repeater in `ProductForm`, are both scoped to the product's own
axes — grouped by attribute name, so the dropdown reads "Scent: Vanilla,
Rose" and "Volume: 30ml, 50ml" rather than a flat, unscoped list of every
value in the catalogue. This is a UI convenience, not the enforcement layer:
the Action's own checks above hold regardless of what the form happens to
show, which is what makes the "two tabs open" case in the table above
reachable at all — the value was a legal option when the page rendered, and
stopped being one before the submission arrived.
