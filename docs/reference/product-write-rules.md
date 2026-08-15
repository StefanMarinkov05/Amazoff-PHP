# Product and variation writes — expected behaviour

What happens for every write against a product, its variations, and their
stock, alone and under concurrency. Facts as of 2026-08-15, measured against
the running stack rather than read off the code.

Why the mechanisms differ is `explanation/concurrency-and-locking.md` and
ADR-0008. What protects each contested row and which test proves it is
`reference/concurrency-coverage.md`. This page is the outcomes.

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
sellability: *every sellable product has at least one variation*. A draft with
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
| | product is soft-deleted | `RemovedFromCatalogueException` |
| `DeleteProduct` | — never refuses; reserved stock is allowed | — |
| `ForceDeleteProduct` | product is on an order line, review, or wishlist | `ProductCannotBeErasedException` |
| | any variation has a ledger or an order line | `VariationCannotBeErasedException` |
| `RemoveProductVariation` | last live variation of an available product | `ProductRequiresVariationException` |
| | stock is reserved against it | `VariationHasReservedStockException` |
| `ForceDeleteProductVariation` | it has any stock movement | `VariationCannotBeErasedException` |
| | it is on an order line | `VariationCannotBeErasedException` |
| | stock is reserved against it | `VariationHasReservedStockException` |
| | it is the last live variation of an available product | `ProductRequiresVariationException` |
| `ReserveStock` | variation is soft-deleted, including via its product | `RemovedFromCatalogueException` |
| | `current − reserved` is below the request | `InsufficientStockException` |

Authorization is checked **before** domain validation everywhere, so an actor
who may not perform the operation never learns which argument was wrong.

A null actor is the system — a seeder, a webhook, a queued job — and skips the
policy check only. Every domain rule above still applies.

## Two actors at once

### Editing different fields of one product

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
**Exactly one wins, in either order.**

| First to commit | Second gets |
|---|---|
| publish | removal refused — available product, one variation |
| removal | publish refused — zero variations |

Without the lock **both commit**, because no `CHECK` constraint can span two
tables — that is why `tests/Concurrency/PublishProductConcurrencyTest.php`
asserts the winner count where the stock race test asserts the failure type.

### Adding a variation while another is removed

Safe in both orders, and `AddProductVariation` takes **no** product lock —
deliberately. Adding can only move the invariant in the safe direction, so
there is no interleaving where it causes a breach. If the removal's snapshot
predates the add, it counts one variation and refuses; that is conservative,
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

### Two products created with the same SKU or slug

`UNIQUE` constraints decide it. One succeeds, the other raises
`QueryException` and the whole `CreateProduct` transaction rolls back —
including variations and stock rows already written for it. The loser sees a
500 rather than a validation message.

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
