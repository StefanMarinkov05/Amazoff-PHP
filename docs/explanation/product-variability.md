# Product variability

How 1 product becomes many sellable things, and which table answers which
question. Describes the system as it is on 2026-08-23. Decisions are argued in
ADR-0002 (schema), ADR-0007 (Actions), and ADR-0013 (variation images); this
page assumes them and shows the arrangement they produce.
`reference/schema/product-catalogue-worked-example.md` makes the same arrangement
concrete with 1 product's actual rows, table by table — read that one first
if the relationships below feel abstract.

## The four questions a variation answers

A `Product` is the thing a customer searches for. A `ProductVariation` is the
thing they buy — one SKU, one row in `order_items`, one stock ledger. Between
them, four separate questions have four separate homes, and keeping them
separate is most of the design:

| Question | Answered by | Shape |
|---|---|---|
| What makes this one different? | `attribute_value_product_variation` | pivot onto `attribute_values` |
| What does it cost? | `ResolveVariationPrice::current()` | override-or-inherit, computed |
| Can it be bought? | `product_variations.is_available` + `inventories` | flag and counters |
| What does it look like? | `product_image_product_variation` | ordered pivot onto `product_images` |

Two of those four are computed rather than stored, and that is deliberate.
Price and representative image are both *derived* from a variation's own value
falling back to the product's, so there is no second copy to go stale and no
write path that can forget to update one. `ResolveVariationPrice` and
`ResolveVariationImage` are plain functions in `App\Support`, not Actions,
because they write nothing.

## Why the differentiator is a pivot and not columns

`product_variations` has no `colour` and no `size`. What distinguishes a
variation is rows in `attribute_value_product_variation`, pointing at
`attribute_values`, which point at `attributes`. Adding "Material" to a product
line is data entry, not a migration.

The cost is that no database constraint can say "every variation of this
product must specify exactly one value for every attribute the product uses".
That is an application invariant, in the same class ADR-0005 records for the
other rules the schema cannot express — enforced today by
`App\Actions\Catalogue\SetVariationAttributeValues`, but only the "at most
one value per attribute, and no two variations identical" half.
Completeness — every declared axis actually filled on every variation — is
not enforced anywhere: a perfume with Scent and Volume as its axes can have
a variation carrying only a Scent, and nothing refuses it.
`reference/write-rules/product-variation-attribute-values.md` is the
outcomes page.

## Two pivots over one vocabulary: axis versus description

`attribute_values` is used twice, by two pivots answering two different
questions:

| | `attribute_value_product_variation` | `attribute_value_product` |
|---|---|---|
| Question | what makes this one *different* | what is this *made of* |
| Customer picks between them | yes | no |
| Forks the SKU | yes | no |
| Several values of one attribute | never | normal |
| Owned by | `SetVariationAttributeValues` | `SetProductAttributeValues` |

The second was added because the first cannot express a blend. "50% cotton,
50% polyester", a perfume's four base notes, and a multicoloured print are
single facts about one sellable thing — but two values on one axis makes
"which variation is Material=cotton?" ambiguous and breaks the combination
uniqueness the grid depends on. Modelling a blend as two variations would
invent two SKUs that do not exist and two stock ledgers nobody can count.

The dividing question is **does the customer choose between them**. A
multicoloured jacket is one variation whose colourway is named "Floral
Print" — an axis value the customer picks — with the constituent colours
descriptive. A perfume with two distinct blends is two variations of a
**Scent** axis whose values are blend names ("Warm Amber", "Rose Musk"),
with the note breakdown descriptive underneath.

An attribute must not be both for one product: a shirt that varies by
Colour cannot also assert one product-wide Colour, or a filter reading both
pivots would return it for a colour no variation has.
`AttributeValueIsAVariationAxisException` enforces that.

**Why not `product_specifications` for the descriptive half.** It exists,
it is free-text `name`/`value`, and it is the right home for prose a
customer reads but nobody filters on. It cannot back a filter: nothing
constrains its `value`, so "Cotton", "cotton", and "100% Cotton" are three
values no query can group. A controlled vocabulary is the whole reason the
second pivot is a pivot, and `attributes.is_filterable` — a flag that had
sat unread since the schema was generated — is what gates it.

`reference/write-rules/product-attribute-values.md` has the outcomes.

## Why images are shared and the gallery is a set

`product_images` belongs to the product. A variation does not own images; it
selects some of the product's, in an order. This is the single biggest reason
the model scales sideways: a product photographed in five colours across eight
sizes has 40 variations and about thirty photographs, not two hundred and
forty.

It also means three states are distinguishable, where a per-variation image
table would have collapsed them:

- an image in no gallery — a size chart, a packaging shot, a lifestyle
  photograph, which belongs to the product and no variation;
- an image in one gallery — a photograph specific to one SKU;
- an image in several — the red photographs, on every red variation.

The gallery is written as a whole set by `SetVariationImages` rather than one
membership at a time. That choice removes a class of concurrency problem
rather than solving it: two administrators reordering, or one adding while
another removes, are the same operation, and the `products` lock serialises
them. `reference/write-rules/product-variation-images.md` has the outcome
table.

## Reviews and descriptions stay at the product

Neither is per-variation, and both were considered. A review carries opinions
about the product, the brand, and the delivery, and `product_reviews` already
constrains one review per customer per product — moving it down would multiply
that constraint by the grid and fragment ratings into single-sample averages.
The variant that was actually bought is still recoverable: `order_item_id`
reaches `order_items.product_variation_id`.

A description per variation was rejected on the grounds that the difference is
already structured as attribute values, and prose a variation needs that its
product does not usually means the variation should be its own product.
ADR-0013 has both arguments in full.

## Where this stops scaling, and what the industry does instead

Nothing here is a load problem at the size this project will reach. The
techniques below are named so the next person recognises them rather than
reinvents them, and so it is clear which ones this codebase has already taken.

**Already here.** The product/SKU split with attributes as a pivot rather than
columns. A shared media table with per-context association, which is
structurally what Magento's `media_gallery` plus `value_to_entity` does. Hot,
frequently-written counters in their own table — `inventories` rather than
columns on `product_variations`.

**The one worth designing toward.** Listing and search pages should not join
5 tables per row. Large catalogues serve them from a flattened projection —
a materialised `product_card` table, or a search index such as OpenSearch or
Algolia — carrying product, default variation, main image path, price range,
and rating, rebuilt on write. Nothing here does that yet, and nothing needs to;
what matters now is not letting a Blade template query the transactional tables
directly, because that is the habit that makes the projection impossible to
introduce later.

**Deliberately not done.** Media attached to the *attribute value* rather than
the variation — Magento's swatch model — which would be six pivot rows instead
of two hundred and forty and one edit when the red photographs change.
Rejected in ADR-0013 for reasons that are about this project's stage rather
than the technique: it makes the attribute system load-bearing, it is the
slower read, and `position` scoped per attribute value has no defined merge
order for a variation holding two image-bearing attributes. It is the thing to
revisit if the catalogue grows wide enough that per-SKU attachment becomes a
merchandising burden.

**Also deliberately not done.** Content-addressed image storage behind a CDN —
hashing the file, deduplicating identical uploads, generating renditions
through an image proxy. That is what makes "the same photograph on 40
variations" free at the storage layer, and it is also what makes orphaned-asset
cleanup a background concern rather than an inline one. Out of scope; named
because it is the real answer to page weight, which dominates anything the
query layer does.
