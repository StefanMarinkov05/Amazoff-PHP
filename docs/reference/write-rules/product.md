# Product and variation writes — expected behaviour

What happens for every write against a product, its variations, and their
stock, alone and under concurrency. Facts as of 2026-08-15, measured against
the running stack rather than read off the code.

Why the mechanisms differ is `explanation/concurrency-and-locking.md` and
ADR-0008. What protects each contested row and which test proves it is
`reference/write-rules/concurrency.md`. This page is the outcomes.

## What enforces any of this

The Actions in `app/Actions/Catalogue` and `app/Actions/Inventory`, and
nothing else. Direct Eloquent, a factory, a seeder, a raw query builder, or a
Filament page writing the model itself all bypass every rule below — ADR-0004
names this as a consequence it accepted, and CLAUDE.md states the convention
as a rule because nothing can enforce it.

**No panel or storefront code calls these Actions yet**, so the panel's
current behaviour is Filament's defaults, not this page.

## Can a product exist with zero variations?

**Yes, and only while it is unavailable.** §6–7 words the invariant around
sellability: *every sellable product has at least 1 variation*. A draft with
nothing in it yet is a half-entered record, not a broken one.

Cart lines are never a reason to refuse an erase. A cart is transient,
self-repairing state with no historical value, so both erase Actions delete
those rows instead — refusing would let a customer pin a catalogue row
indefinitely by leaving a tab open, and the line was going to die at checkout
anyway. A cart that got as far as checkout holds a *reservation*, and reserved
stock is still refused.

`DeleteProduct` soft-deletes the variations with the product, so a deleted
product's variations are unreservable and its stock rows and ledger survive.
`ForceDeleteProduct` erases children before the parent, refusing wherever
something must outlive the record.

| Path | Zero variations possible? |
|---|---|
| `CreateProduct` | **No.** An empty `$variations` throws before anything is written |
| `RemoveProductVariation` on the last one, product unavailable | **Yes.** Allowed, product ends with zero |
| `RemoveProductVariation` on the last one, product available | No — refused |
| `ForceDeleteProductVariation`, product unavailable | **Yes** |
| `UpdateProduct` setting `is_available = true` on a product with zero | No — refused |
| `Product::factory()`, seeders, direct Eloquent | **Yes**, unguarded |

So the reachable state is *unavailable product, zero variations*, and it is
deliberate. The storefront never renders it because it is unavailable, and
`UpdateProduct` refuses to publish it until a variation exists.

## One actor at a time

| Operation | Refused when | Exception |
|---|---|---|
| `CreateProduct` | no variations given | `ProductRequiresVariationException` |
| | actor lacks `create_product` | `AuthorizationException` |
| | actor lacks `create_product_variation` | `AuthorizationException` (from the nested Action) |
| | a variation's SKU already exists | `QueryException`, whole product rolled back |
| `AddProductVariation` | product is soft-deleted | `RemovedFromCatalogueException` |
| | opening stock is negative | `InvalidArgumentException` |
| `UpdateProduct` | `is_available = true` and zero live variations | `ProductRequiresVariationException` |

`products.is_available` counts variations **existing**, not variations
available. A product may be available while every variation is
`is_available = false`, and that is deliberate: §6 gives the merchandiser a
switch, and a product visible-but-unbuyable is how a shop signals "this is
coming back". It is not a giant OR of its variations and must not become one
— deriving the column would remove the override.

The consequence a storefront query must carry: a listing that shows only
buyable products filters on `products.is_available` **and** a
`whereHas('productVariations', is_available)`. Filtering on the product flag
alone shows products with nothing to buy, which is intended for a product
page and wrong for an "in stock" filter.
| | product is soft-deleted | `RemovedFromCatalogueException` |
| `DeleteProduct` | — never refuses; reserved stock is allowed | — |
| `ForceDeleteProduct` | product is on an order line, review, or wishlist | `ProductCannotBeErasedException` |
| | any variation has a ledger or an order line | `VariationCannotBeErasedException` |
| `RemoveProductVariation` | last live variation of an available product | `ProductRequiresVariationException` |
| | stock is reserved against it | `VariationHasReservedStockException` |
| `ForceDeleteProductVariation` | it has any stock movement | `VariationCannotBeErasedException` |
| | it is on an order line | `VariationCannotBeErasedException` |
| | stock is reserved against it | `VariationHasReservedStockException` |
| | it is the last live variation of an available product, unless `ForceDeleteProduct` is erasing that product too | `ProductRequiresVariationException` |
| `ReserveStock` | variation is soft-deleted, including via its product | `RemovedFromCatalogueException` |
| | `current − reserved` is below the request | `InsufficientStockException` |

Authorization is checked **before** domain validation everywhere, so an actor
who may not perform the operation never learns which argument was wrong.

A null actor is the system — a seeder, a webhook, a queued job — and skips the
policy check only. Every domain rule above still applies.

`ForceDeleteProductVariation` deletes the stock row before the variation row.
`inventories.product_variation_id` is a `NO ACTION` foreign key, so the
reverse order is error 1451 — which is what Filament's default
`ForceDeleteAction` still does for every variation that has one.

## Two actors at once

### Editing different fields of 1 product

The outcome depends on **what the form submits**, not on what the employee
changed. All three rows are measured in
`tests/Feature/Actions/Catalogue/ConcurrentProductEditTest.php`.

| Both submit | Instances loaded | Outcome |
|---|---|---|
| the whole record | both read before either wrote | **Both edits survive.** The stale value equals that instance's own original, so it is not dirty and never enters the `UPDATE` |
| the whole record | the second re-read after the first committed | **The first edit is silently reverted.** Measured against current originals, the untouched field *is* a change |
| only the field they changed | either | **Both edits survive** |

The middle row is what the admin panel always does. Livewire re-resolves the
record from the database when the request hydrates, so `$this->record` carries
the other employee's new value as its baseline while the submitted payload
still carries what the form loaded minutes earlier.

Nothing warns either employee. See gap 1.

### Editing the same field

**Last write wins, silently, in every case** — full payload or partial,
whichever instance. No conflict is detected and neither employee is told.

Partial submission narrows *which* fields can collide; it does nothing once
two people are editing one field.

### A cross-field constraint between them

A lowers `regular_price` to 50 while B, whose form loaded 100, sets
`discount_price` to 80:

| B submits | Outcome |
|---|---|
| only `discount_price` | `QueryException` — `chk_products_discount_below_regular` rejects 80 against the now-current 50 |
| the whole record | **Succeeds**, and silently reverts A's price back to 100 |

The constraint is what turns silent corruption into a loud failure. Partial
submission moves the failure rather than removing it.

### Publishing while the last variation is removed

Serialised: both Actions take `lockForUpdate()` on the `products` row.
**Exactly one wins, in either order.** True whether the removal is
`RemoveProductVariation` (soft delete) or `ForceDeleteProductVariation`
(permanent) — both lock the same row for the same reason and share the same
outcome.

| First to commit | Second gets |
|---|---|
| publish | removal refused — available product, 1 variation |
| removal | publish refused — zero variations |

Without the lock **both commit**, because no `CHECK` constraint can span 2
tables — that is why `tests/Concurrency/PublishProductConcurrencyTest.php`
(soft delete) and `tests/Concurrency/ForceDeleteProductVariationConcurrencyTest.php`
(permanent) assert the winner count where the stock race test asserts the
failure type.

### Adding a variation while another is removed

Safe in both orders, and `AddProductVariation` takes **no** product lock —
deliberately. Adding can only move the invariant in the safe direction, so
there is no interleaving where it causes a breach. If the removal's snapshot
predates the add, it counts 1 variation and refuses; that is conservative,
not wrong.

### Reserving stock while the variation is removed

Serialised on the `inventories` row. Safe in both orders:

| First to commit | Second gets |
|---|---|
| removal | `RemovedFromCatalogueException` — the re-read finds the variation gone |
| reservation | `VariationHasReservedStockException` — removal refuses to strand held stock |

### Two customers reserving the last unit

One succeeds; the loser gets `InsufficientStockException` — a handled "out of
stock", not a 500. Without the lock the loser instead hits
`chk_inventories_reserved_not_above_current` and gets a `QueryException`.
Nothing is oversold either way; only the failure mode differs.
`tests/Concurrency/ReserveStockConcurrencyTest.php`.

### Two releases of the same reservation

Same shape, opposite direction. One succeeds; the loser gets
`InvalidArgumentException` rather than driving `reserved_quantity` negative.
Without the lock the loser instead hits
`chk_inventories_reserved_quantity_non_negative` and gets a `QueryException`.
`tests/Concurrency/ReleaseStockConcurrencyTest.php`.

### 2 products created with the same SKU or slug

`UNIQUE` constraints decide it. One succeeds, the other raises
`QueryException` and the whole `CreateProduct` transaction rolls back —
including variations and stock rows already written for it. The loser sees a
500 rather than a validation message.

## Default variation

A product with variations has **exactly one default variation**, the same
shape as the main-image rule below and enforced the same way —
`SetDefaultVariation`, one `UPDATE` (`is_default = (id = N)`), no lock.

| Operation | Outcome |
|---|---|
| First variation added | becomes default, whether or not it was asked for |
| Later variation added | not default, unless `is_default: true` is passed |
| Later variation added as default | the previous default is demoted |
| Promotion | siblings demoted in the same statement |
| Default variation removed, others remain | the first remaining sibling succeeds it |
| Last variation removed | refused already — `ProductRequiresVariationException` — so this case cannot arise |
| Two promotions at once | both succeed; the later one wins; one flag survives |

Variation-level weight and dimensions follow the same null-inherits-the-product
pattern as `price`: `null` on a variation means "same as the product's own
value," set only when a variation genuinely differs. Inheritance is **per
axis** — a hardcover edition is the same page size as its paperback sibling
and only thicker, so it overrides `height_mm` and `weight_g` and leaves the
other two null rather than restating values that are genuinely identical.
`ResolveVariationMeasurements` performs that resolution and is what callers
must read; `$variation->weight_g` on its own returns null for the common
inheriting variation, and a null weight handed to a courier is a zero-weight
parcel rather than an error.

Dimensions have no per-variation display unit — a variation's dimensions are
entered and shown in the product's `dimension_display_unit`, never its own.

## Images

A product with images has **exactly one main image**. The database does not
enforce it — verified: it accepts two `is_main = 1` rows for 1 product — so
the rule lives in `SetMainProductImage`, expressed as one `UPDATE`.

| Operation | Outcome |
|---|---|
| First image added | becomes main, whether or not it was asked for |
| Later image added | not main, unless asked |
| Later image added as main | the previous main is demoted |
| Promotion | siblings demoted in the same statement |
| Main image removed, others remain | the lowest `sort_order` succeeds it |
| Last image removed | the product has no main image, which is legal |
| Removing an image variations are showing | allowed; it leaves every gallery it was in |
| Two promotions at once | both succeed; the later one wins; one flag survives |

Removing an image is no longer refused. Until ADR-0013,
`product_variations.image_id` was a `NO ACTION` foreign key, so a referenced
image produced error 1451 and `RemoveProductImage` converted it into
`ProductImageInUseException` — a soft-deleted variation counted too, since it
kept the key. That column and that exception are both gone; the variation
gallery that replaced them cascades.

Images are not soft-deleted. The file on disk is deleted after the transaction
commits — the ordering that matters, since a row intact and a file gone is the
one combination nothing can repair.

A variation's *own* gallery, which images it holds and in what order, is a
separate aggregate with its own page: `product-variation-images.md`.

## Specifications

No Action, deliberately. One table, no invariant, no second writer — ADR-0007's
threshold is not met and CLAUDE.md's rule applies: wrapping a single-table save
in an Action buys no consistency and costs a class. Default Filament CRUD.

## Price history (Omnibus prior-price display, ADR-0021)

`product_price_history` records the product's **effective** selling price
(`ResolveProductPrice::current()->current` — the discounted price while the
window is open, the regular price otherwise) so the storefront can show "the
lowest price in the 30 days before a reduction".

| Trigger | What gets written |
|---|---|
| `CreateProduct` | one row — the product's initial effective price |
| `UpdateProduct` | one row **only when** `regular_price` / `discount_price` / `discount_starts_at` / `discount_ends_at` changed **and** the resulting effective price differs from the most recent observation |
| `products:snapshot-prices` (daily) | one row per product, unconditionally — so a discount window opening or closing on schedule, with no admin edit, still produces a data point |

`RecordPriceObservation` is the per-product writer; `RecordProductPrices` is
the daily sweep. Neither authorizes anything — they observe, they do not
mutate the product, and their callers are already gated. The table is
append-only; the only deletion is the `product_id` cascade on
`ForceDeleteProduct`.

`ResolvePriorPrice::forProduct()` reads it: `MIN(price)` over the 30 days
before `discount_starts_at` (or now), with a fallback to the last row before
that window. Returns `null` when the product is not on sale or has no usable
history — a product cannot honestly claim a prior price it never had.

**Not tracked:** per-variation price overrides. The prior price is
product-level (ADR-0021 decision 4). Delivery-cost reimbursement and the
exact ЗЗП чл. 6б wording are counsel gaps.

## Review eligibility (§24)

§24 only requires "bought it" — nothing says the order must still be open
or successfully delivered. `OrderItem::reviewableBy()` is the single scope
`CreateProductReview` and `ProductDetails::canReview()` both call; it
accepts a line whose order's `order_status_histories` shows one of:

- a `Delivered` row,
- a `Returned` row (with or without a `Delivered` row before it — `Shipped`
  can transition straight to `Returned`, e.g. refused at the door), or
- a `Cancelled` row whose `previous_status` is not `AwaitingPayment`.

The last one is the boundary: a cancellation still in `AwaitingPayment` is
the expiry sweep, a failed webhook, or the customer's own Cancel button —
none of those means anyone received anything, so that shape is excluded.
Anything cancelled after the customer committed (from `Confirmed` onward)
counts, on the same reasoning as the other two: they bought it.

`previous_status` rather than the history row's `user_id`: a staff account
can be deleted (`user_id` nulls on delete per the FK), which would otherwise
silently strip eligibility from every order that account cancelled.

Reading history rather than the order's current `status` is also what keeps
this decoupled from later state: a whole-order `Returned`/`Refunded` staff
move, or a customer return through `RequestReturn` (which never touches
`orders.status` — see `write-rules/returns.md`), does not retract that the
product was actually delivered.

## Lock order

`products` before `inventories`, always. `ReserveStock` and `ReleaseStock`
take `inventories` alone and never reach for a product, so no cycle exists.
`CreateOrder` will be the first to lock several `inventories` rows at once and
must sort them by primary key.

## Known gaps

**1. Lost update on product edits.** The middle row of the disjoint-fields
table. Live on every full-payload Filament form in the codebase, not only
products. Closing it needs optimistic concurrency, which ADR-0008 defers with
reasons. Pinned by tests that assert the defect, so they flip red when it is
fixed.

**2. Nothing enforces any of this outside the Actions,** and no panel or
storefront code calls them yet.
