# Catalogue filters — options and expected behaviour

What `/catalogue` (`App\Livewire\Catalogue\ProductList`) actually filters
on, how each filter combines with the others, and what happens on input
that does not fit — a wrong slug, a malformed number, a tier outside the
allow-list. Facts as of 2026-08-29, verified against the running app (live
`curl` requests with injection-shaped and oversized input, not only Pest),
not read off the code. Same shape as `write-rules/cart.md`; read
`explanation/storefront-pages.md` first for the read-path conventions this
follows (ADR-0014).

## What enforces any of this

`ProductList::applyFilters()`, the one definition shared by the product
query and both facet counts (`categories()`, `brands()`) — a filter added
there cannot be forgotten in a count. Nothing else on the storefront
filters the catalogue.

## The filters

| Filter | Property | URL key | Type |
|---|---|---|---|
| Search | `search` | `search` | free text, matched against `name`/`short_description` |
| Category | `categorySlug` | `category` | slug, resolved to a family of ids |
| Brand | `brandId` | `brandId` | id |
| In stock only | `inStockOnly` | `inStockOnly` | boolean |
| On sale only | `onSaleOnly` | `onSaleOnly` | boolean |
| Min / max price | `minPrice` / `maxPrice` | `minPrice` / `maxPrice` | decimal string, against `regular_price` |
| Minimum rating | `minRating` | `minRating` | one of `ProductList::RATING_TIERS` (`4, 3, 2, 1`), or unset |

Every filter is `#[Url]`, so a filtered catalogue is a shareable, bookmarkable
link — ADR-0014's own reasoning for the whole page, extended to each filter
added since.

## Category: a node means itself plus every descendant

Selecting a category shows products assigned to that category **or any of
its descendants, at any depth** — not an exact match against the row picked.
`App\Support\ResolveCategoryFamily::selfAndDescendantIds()` is the one place
this walk happens.

The real seeded tree is 4 levels deep (Clothing → Men → Tops → T-Shirts,
confirmed by walking it — an early sample of only the shallow branches read
as 2 levels and was wrong). A non-leaf category can hold products directly
on itself *and* have children that also hold products — Garden has 3 of its
own, plus 2 on Mowers and 2 on Watering. Selecting Garden shows all 7. This
was a deliberate scope decision, not a default: "descendants only, excluding
the parent's own" was the rejected alternative.

**The URL carries a slug, not an id** (`?category=mens-jackets`). A slug
matching no category — a stale bookmark, a hand-edited URL, a deleted
category — is treated as no filter at all: the catalogue falls back to
unfiltered rather than erroring or showing an empty page. Verified live:
`?category=<script>alert(1)</script>`, `?category=' OR '1'='1`, and a
5,000-character slug each return `200`, with no reflection of the raw value
and no change to the unfiltered product count. Structural, not filtered
input: `categorySlug` only ever reaches a parameterised Eloquent `where()`.

The sidebar `<select>` and the header mega-menu both read categories from
this same family logic — a sidebar count of "Garden (7)" and clicking it
landing on 7 products is the same guarantee facet counts already give
search/brand: a count never promises more than the click delivers. The
mega-menu shows two levels only (top-level, then direct children) on hover;
reaching a category deeper than that goes through the sidebar, which is
ordered depth-first (parent immediately before its own children, indented)
rather than the flat, 173-row alphabetised list it was before.

## Price: against the sticker price, not the discount-window price

`minPrice`/`maxPrice` filter `products.regular_price` — the sticker price,
**not** the discount-aware price `App\Support\ResolveProductPrice` resolves
for display. A product currently on sale at €50 (regular €80) will not match
`maxPrice=60`, because the comparison never reaches the discount window.

This is a deliberate scope cut, not an oversight. `ResolveProductPrice::
windowActive()` depends on `Carbon::now()` against nullable date columns —
expressible as SQL, but only by writing that window logic a second time,
directly against raw columns, in the filter clause. ADR-0014 already names
the exact failure this risks: "a card can advertise a sale price the cart
then refuses to honour," for the unrelated case of `ProductList` once
duplicating `windowActive()` itself. Filtering by sticker price avoids
reintroducing it. If effective-price filtering is wanted later, it belongs
as a change to `ResolveProductPrice` (or a SQL-safe counterpart it owns),
not as inline logic in `applyFilters()`.

**Malformed input resets to "no bound," never partially clamped.** There is
no sensible nearest-valid price to a string that was never a price.

| Input | Result |
|---|---|
| Empty string | `null` — no bound |
| Non-numeric (`"abc"`) | `null` |
| Negative (`"-50"`) | `null` |
| A number too large for PHP to represent as an `int`/`float` cleanly | `null` — does not crash |
| Valid (`"49.9"`) | stored at 2-decimal scale (`"49.90"`), matching the `decimal(10,2)` column it compares against |
| `minPrice` set above the current `maxPrice` | `maxPrice` raised to match — the bound the customer just touched wins, never silently discarded |

`$minPrice`/`$maxPrice` are declared `mixed`, not `?string` or `?float` —
the same reasoning as `ProductDetails::$quantity` (`write-rules/cart.md`'s
sibling note doesn't cover this, see the CHANGELOG entry instead): Livewire
assigns whatever the client sends to a property before any of this class's
own code runs, and a strictly-typed numeric property throws
`TypeError: Cannot assign string to property ... of type ?float` on a value
PHP cannot represent, before the sanitisation in `updatedMinPrice()`/
`updatedMaxPrice()` ever executes. Verified live:
`?minPrice=99999999999999999999999999999999` and
`?minPrice=' OR 1=1--` both return `200`.

## Rating: a product with no approved reviews is always shown

`minRating` excludes a product only when it **has** approved reviews **and**
their average is below the selected tier. A product with zero approved
reviews is shown at every tier, including the strictest (`4★ & up`) — this
is the rule, not a gap: a customer filtering "4 stars & up" is asking to
exclude products known to disappoint, not to exclude products nothing is
known about yet.

An **unapproved** review does not count as "having a review" either — a
review pending moderation is not evidence of quality one way or the other,
so a product whose only review is unapproved is treated the same as a
product with none.

The check is `whereDoesntHave(approved reviews) OR (average of approved
reviews >= tier)` — both branches are required. `whereDoesntHave` alone
would also admit a product whose approved reviews average *below* the tier,
because "does not have zero reviews" is true for it and nothing then checks
the average.

`RATING_TIERS = [4, 3, 2, 1]` is the full allow-list — ADR-0014's rule for
any `#[Url]`-bound value that reaches a query. There is no "5 & up" tier:
indistinguishable in practice from "4 & up" once an unrated product is
always shown regardless, and Amazon's own rating filter stops at 4.

| Input | Result |
|---|---|
| A value in `RATING_TIERS` | applied |
| Any other value (`5`, `0`, `"abc"`, empty string) | `null` — no rating filter |
| A number too large for PHP to represent as an `int` | `null` — does not crash |

`$minRating` is `mixed`, the same reasoning as price and `ProductDetails::
$quantity` — the radio inputs in the UI only ever send `"4"`–`"1"` or `""`,
but a crafted request is not bound by what the markup offers. Verified
live: `?minRating=99999999999999999999999999999999` and
`?minRating=<script>alert(1)</script>` both return `200`, with `minRating`
reset to `null`.

## What is not filtered, and why

- **Availability floor** — `is_available = true` is applied unconditionally,
  first, in `applyFilters()`, not exposed as a toggle. Nothing on the
  storefront should be able to ask for unavailable products; that is what
  `is_available = false` means.
- **Variation-level attributes** (size, colour, material) — §37's filter
  requirement is at the product level; no per-attribute facet exists yet.
  Not the same gap as the ones above — no code path pretends to cover it.
- **Effective/discount price** — see "Price," above. `regular_price` only.

## Facet counts follow the same rules as the filters

`categories()` and `brands()` compute their displayed count against every
*other* active filter (search, price, rating, the opposite of
category/brand) but not the facet's own dimension — picking a category
should never make every other category read zero. A category's count is
its family sum (itself plus every descendant), the same total selecting it
resolves to; a facet whose count is stale is worse than no count, because it
promises results that are not there.

There is no facet count for price or rating (no discrete option list to
count against a numeric range or a five-way tier the way a category or
brand id has one row per option).
