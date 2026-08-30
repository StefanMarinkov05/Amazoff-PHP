# Product attribute-value writes — expected behaviour

What happens setting a product's **descriptive** attribute values — "what is
this made of" — alone and under concurrency. The variation grid's own values
("what makes this one different") are
`product-variation-attribute-values.md`, a different pivot answering a
different question with the same vocabulary.

Facts as of 2026-08-30, measured against the running stack. Reasoning is in
ADR-0005 (both rules here are its un-constrainable class) and
`explanation/product-variability.md` (why the two pivots are separate at
all).

## What a descriptive value set is

`attribute_value_product`: `attribute_value_id`, `product_id`. Composite
primary key over the pair, both foreign keys cascading, plus an index on
`product_id` for the reverse read.

A fact true of every variation, which the customer does not choose between
and which must never fork the SKU — a fabric composition, a set of scent
notes, a certification. `Product::descriptiveAttributeValues()`; the longer
relation name is deliberate, since `Product::attributes()` beside it means
something else entirely (which axes the product *varies* by).

## The one asymmetry that justifies a second pivot

**Multiple values per attribute are legal here and illegal on a variation.**

"50% cotton, 50% polyester" and a perfume's four base notes are single facts
about one sellable thing. `SetVariationAttributeValues` refuses that shape
with `DuplicateVariationAttributeException`, and correctly: two values for
one axis makes "which variation is Material=cotton?" ambiguous and breaks
the combination uniqueness the grid depends on. Modelling a blend as two
variations would invent two SKUs that do not exist.

The dividing line, when it is unclear which pivot a value belongs in:

| | Variation axis | Descriptive value |
|---|---|---|
| Customer picks between them | yes | no |
| Forks the SKU | yes | no |
| Several values at once | never | normal |
| Example | Size, Volume, the *name* of a colourway | Material, scent notes, certification |

A multicoloured jacket is one variation whose colourway is named "Floral
Print" (an axis value), with the individual colours descriptive — the
customer picks the print, not "red AND blue".

## What enforces any of this

`App\Actions\Catalogue\SetProductAttributeValues`, and nothing else. Direct
Eloquent, a factory, or `attach()` on the relation all bypass every rule
below. The composite primary key still refuses a duplicate pairing and both
foreign keys still refuse a non-existent id, but neither can express the two
rules that matter.

## One actor at a time

| Operation | Outcome |
|---|---|
| Set to a list of values | replaces the whole set |
| Set to an empty list | cleared |
| Two values of the same attribute | **allowed** — the point of this pivot |
| Same value given twice | collapsed silently |
| A value whose attribute the product uses as a variation axis | refused — `AttributeValueIsAVariationAxisException`, naming the attribute |
| A value whose attribute is not allowed for the product's category | refused — `AttributeNotAllowedForCategoryException` |
| A product soft-deleted since the page loaded | refused — `RemovedFromCatalogueException` |

The axis clash is refused rather than merged because the two would
contradict each other: a shirt that varies by Colour cannot also assert one
product-wide Colour, and a storefront filter reading both pivots would
return the product for a colour no variation actually has.

The category rule is the same `attribute_product_category` allow-list
`CreateProduct`/`UpdateProduct` enforce for axes — a perfume has no business
carrying a shoe size descriptively either. An attribute with no rows in that
table is unrestricted.

## Concurrency

Locks `products`, the same row and the same order as `SetVariationImages`,
`SetVariationAttributeValues`, and `ForceDeleteProductVariation` — the
shared order is what keeps them from deadlocking against each other.

| Two actors | Outcome |
|---|---|
| Two descriptive-set writes on 1 product | both succeed; the later wins wholesale, the earlier is lost |
| Descriptive write, product soft-delete | serialised; a write landing after the delete refuses with `RemovedFromCatalogueException` |
| Descriptive write, an axis added to the same product | serialised by the product lock; whichever lands second sees the other's state, so the axis-clash rule cannot be straddled |

The lost update is by design, the same reasoning
`product-variation-images.md` gives for the gallery: a complete-set write
has nothing to reconcile between two submissions, and this is not the
treatment stock or coupon caps get, where a lost write is money.

Unlike the gallery and the variation combination, this Action has **no
dedicated concurrency test**. Its lock is the same lock on the same row
taken in the same order as three Actions that do have one, and the failure
it prevents is the same set-vs-set interleave
`SetVariationAttributeValuesConcurrencyTest` demonstrates 4-of-4 with the
lock removed. Recorded as a known gap rather than implied to be covered.

## Filtering

This pivot is what makes "filter by material" possible, and is the reason it
is a controlled vocabulary rather than free-text `product_specifications`:
nothing constrains a specification's `value`, so "Cotton", "cotton", and
"100% Cotton" are three values a filter cannot group.
`attributes.is_filterable` finally has a consumer.

`ProductList::$attributeValueIds` (`?attributeValueIds[]=`) is the storefront
filter. Selected values are grouped by attribute in
`filterableAttributeValueIdsByAttribute()`: values within one attribute are
**OR**ed (checking Black and White means either colour — no variation is
ever both at once), and each attribute's group is **AND**ed against every
other attribute's (Colour=Black AND Material=Cotton narrows, since those can
coexist). Ids are re-resolved against real, filterable values on every query
rather than trusted from the URL (ADR-0014's allow-list rule); an unknown,
non-filterable, or forged id narrows nothing rather than erroring, the same
silent fall-through `categorySlug` takes. The property is `mixed`-typed and
sanitised in `updatedAttributeValueIds()`, because `#[Url]` hydration assigns
the raw request value before any of the component's own code runs — the same
incident class as `ProductDetails::$variationId`.

`ProductList::attributeFacets()` is **category-scoped**. With no category
picked it returns nothing and only the generic filters (brand, price,
rating, stock, sale) render: Colour and Size mean nothing across a catalogue
that also holds power tools and moisturiser, and a flat list offering every
attribute in the shop at once is the dump this feature exists to avoid.

Once a category is picked, the facets are its allowed attributes —
`ResolveAllowedAttributes::forCategory()`, so **an attribute scoped to a
master category is inherited by every descendant**: Material scoped once to
"Clothing" is offered on "Clothing", "Clothing > Men", "Clothing > Men >
Tops", and every leaf under them, without being re-scoped at any depth. It
is not offered on a sibling branch such as "Beauty & Personal Care".
Verified against the real seeded 4-level tree, not only in fixtures.

Within that scope a facet appears only for values a currently visible
product actually carries, counted against every *other selected attribute* —
`applyFilters()`'s `$skipAttributeId` parameter excludes only the one
attribute a facet belongs to, not the whole attribute-value dimension.
Picking Material=Denim narrows Colour's and Size's own counts to what the
Denim products actually have, but a selected Colour value's own count is
never narrowed by that same Colour selection — otherwise checking Black
would make every colour, Black included, read as if nothing had it. Values
within a facet are ordered by `sort_order`, not alphabetically (XS, S, M, L,
XL, XXL — not L, M, S, XL, XS).

Facets render as clickable toggle buttons above the product grid —
`ProductList::toggleAttributeValue()` adds or removes one value directly
from `$attributeValueIds` — rather than one `<select multiple>` per
attribute in the sidebar. The dropdown form needed a `facetSelections`
staging property because Livewire cannot bind several independent
multi-selects to one shared array without each overwriting the others' picks
on change; toggle buttons have no such conflict, so that property no longer
exists.

Each selected value gets its own dismissible chip, keyed
`attributeValue:N` — the one chip key carrying a payload, since every other
filter is a single value. Dismissing one drops that value alone rather than
clearing the set, widening the search by one step.
