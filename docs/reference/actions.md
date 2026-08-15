# Actions

What exists in `app/Actions` today. Why they are written this way is
ADR-0007; how to add one is `how-to/add-an-action.md`; what each one does when
two of them run at once is `reference/product-write-rules.md`.

Eight Actions across two areas, five domain exceptions.

## Naming

`app/Actions/{Area}/{Verb}{Noun}.php`, one public `handle()` per class. The
area is the aggregate the write belongs to, not the caller — `AddProductVariation`
is `Catalogue` because it writes catalogue tables, although the panel reaches
it through a relation manager and the fixture loader will not.

Classes are `final`. Dependencies, including other Actions, arrive by
constructor injection.

## Inventory

| Action | Writes | Actor | Throws |
|---|---|---|---|
| `RecordInventoryMovement` | `inventory_movements` | optional, recorded as `created_by_id` | — |
| `ReserveStock` | `inventories.reserved_quantity`, `inventory_movements` | optional | `InsufficientStockException`, `InvalidArgumentException` |
| `ReleaseStock` | `inventories.reserved_quantity`, `inventory_movements` | optional | `InvalidArgumentException` |

`RecordInventoryMovement` is the one Action that opens no transaction of its
own and is not meant to be called directly. A movement row without the
quantity change it describes is a false ledger entry, so the caller owns the
boundary.

## Catalogue

| Action | Writes | Actor | Throws |
|---|---|---|---|
| `CreateProduct` | `products`, `product_variations`, `inventories`, `inventory_movements` | optional, checked against `create_product` | `ProductRequiresVariationException` |
| `UpdateProduct` | `products` | optional, checked against `update_product` | `ProductRequiresVariationException` |
| `AddProductVariation` | `product_variations`, `inventories`, `inventory_movements` | optional, checked against `create_product_variation` | `InvalidArgumentException` |
| `RemoveProductVariation` | `product_variations` (soft delete) | optional, checked against `delete_product_variation` | `ProductRequiresVariationException`, `VariationHasReservedStockException` |
| `ForceDeleteProductVariation` | `product_variations`, `inventories` (both erased) | optional, checked against `delete_product_variation` | `VariationCannotBeErasedException`, `VariationHasReservedStockException`, `ProductRequiresVariationException` |

`ForceDeleteProductVariation` deletes the stock row **before** the variation.
`inventories.product_variation_id` is a `NO ACTION` foreign key, so the
reverse order is error 1451 — which is what Filament's default
`ForceDeleteAction` does today for every variation that has a stock row.

Erasing is refused whenever anything depends on the variation: a stock ledger
(§20), an order line (`order_items` is `ON DELETE SET NULL`, so the database
would allow it and silently null the reference), a cart line, or being the
last live variation of an available product.

`CreateProduct` composes `AddProductVariation`, which composes
`RecordInventoryMovement`. The actor is passed down rather than dropped, so
creating a product through `CreateProduct` requires `create_product` **and**
`create_product_variation`.

Opening stock is a parameter of `AddProductVariation` rather than a column on
the variation. Zero writes no movement row.

## Transactions

| Action | Opens `DB::transaction` |
|---|---|
| `ReserveStock` | yes |
| `ReleaseStock` | yes |
| `CreateProduct` | yes |
| `AddProductVariation` | yes |
| `ForceDeleteProductVariation` | yes |
| `UpdateProduct` | yes |
| `RemoveProductVariation` | yes |
| `RecordInventoryMovement` | no |

Nesting is by savepoint, so the outermost boundary commits.
`RecordInventoryMovement` is the exception: it writes one row and is never the
whole operation, so the caller owns the boundary and it joins one.

`UpdateProduct` writes a single row and would not need a transaction for
atomicity. It opens one because `lockForUpdate()` outside a transaction
releases immediately and protects nothing.

## Locking

`ReserveStock` and `ReleaseStock` take `lockForUpdate()` on the inventory row
before reading the quantities. `explanation/concurrency-and-locking.md` covers
what the lock does and what the `CHECK` constraints do instead.

`UpdateProduct`, `RemoveProductVariation`, and `ForceDeleteProductVariation`
take `lockForUpdate()` on the `products` row — the aggregate root — rather
than on the rows they write.
§6–7's invariant spans two tables, which MySQL cannot express as a constraint
and ADR-0004 rejected triggers for, so nothing catches an unlocked race:
without the lock both Actions commit and the invariant is gone. Locking each
Action's own target would have them contend on different rows and wait for
nothing. ADR-0008 records the decision.

Lock order is `products` before `inventories`. `ReserveStock` and
`ReleaseStock` take `inventories` alone, so no cycle exists.
`RemoveProductVariation` and `ForceDeleteProductVariation` take both, in that
order.

Duplicate SKUs and slugs are safe by the `UNIQUE` constraints from ADR-0005
rather than by anything in the Actions. A losing insert raises
`QueryException` rather than a validation error.

**What is still unprotected:** two employees saving product forms opened at
the same time silently revert each other's untouched fields. The window is
human think time, which no lock can span — a PHP request ends when the form
renders. Closing it needs optimistic concurrency, which ADR-0008 defers.
Measured and pinned, including the wrong behaviour, in
`tests/Feature/Actions/Catalogue/ConcurrentProductEditTest.php`.

`reference/concurrency-coverage.md` is the full map.

## Exceptions

| Exception | Raised by | Carries |
|---|---|---|
| `InsufficientStockException` | `ReserveStock` | the variation, requested quantity, available quantity |
| `ProductRequiresVariationException` | `CreateProduct`, `UpdateProduct`, `RemoveProductVariation`, `ForceDeleteProductVariation` | the product, when there is one |
| `VariationHasReservedStockException` | `RemoveProductVariation`, `ForceDeleteProductVariation` | the variation, the reserved quantity |
| `VariationCannotBeErasedException` | `ForceDeleteProductVariation` | the variation |
| `RemovedFromCatalogueException` | `ReserveStock`, `AddProductVariation`, `UpdateProduct` | the record that was removed |
| `ProductCannotBeErasedException` | `ForceDeleteProduct` | the product |

`RemovedFromCatalogueException` covers a soft-deleted row reached through a
model loaded before the deletion — a cart holding a variation an
administrator has since removed, or `ProductResource`'s route binding, which
drops the `SoftDeletingScope` and so opens a deleted product's edit page and
relation managers. Both Actions re-read rather than trusting `trashed()` on
the in-memory model.

`ProductRequiresVariationException` has one named constructor per door the
invariant can be broken through — `atCreation`, `whenMadeAvailable`,
`whenLastVariationRemoved`.

Every exception carries the record it concerns, so a caller can render a
message without parsing one. Where several named constructors raise one class,
tests assert the payload rather than the class alone — asserting the class
passes when the wrong branch fires.

All seven extend `RuntimeException`. `InvalidArgumentException` is used where
the condition is a caller bug rather than something a customer could act on —
a negative quantity, a release larger than the reservation.

## Callers

| Action | Called from |
|---|---|
| `CreateProduct`, `UpdateProduct` | `CreateProduct` / `EditProduct` pages, tests |
| `DeleteProduct`, `ForceDeleteProduct` | `EditProduct` header actions, tests |
| `AddProductVariation`, `RemoveProductVariation`, `ForceDeleteProductVariation` | `ProductVariationsRelationManager`, tests |
| `ReserveStock`, `ReleaseStock`, `RecordInventoryMovement` | composed by the above, tests |

`ProductResource` routes every write through its Action, per ADR-0007. §37
criterion 1 is met for the panel; no storefront exists yet.

Domain exceptions become notifications rather than 500s, via
`App\Filament\Concerns\ReportsDomainFailures`. Only `App\Exceptions` are
caught — a `QueryException` is a defect, not a refusal, and swallowing one
into a toast would hide the failures that should be loud.

The variations relation manager has **no delete or force-delete bulk action**.
Both write Eloquent directly, which is the bypass the wiring exists to close.
Restoring cannot break the invariant, so it stays.

## Test obligations

Each Action's guards have a test that has been observed failing with the
mechanism deleted. Twenty-three were verified for the catalogue slice: the
authorization check on each of the five Actions, the actor passed down from
`CreateProduct`, the transaction on `CreateProduct` and `AddProductVariation`,
the inventory row, the required-variation rule at creation, the sellability
rule on update, the last-variation and reserved-stock guards on removal, the
two quantity guards, the `products` lock shared by `UpdateProduct` and
`RemoveProductVariation`, the two soft-delete guards, the gate ordering in
`AddProductVariation`, and the erase Action's delete order plus its ledger,
cart, and last-live-variation refusals.

`reference/concurrency-coverage.md` records which specific test covers each.

Two tests passed with their mechanism removed and were replaced. The actor in
`denies an actor without create_product` held neither catalogue permission, so
`AddProductVariation` was raising the exception the test attributed to
`CreateProduct`. A fault-injection test for the `products` lock injected its
conflicting write on the same connection, where a row lock is not supposed to
stop it — replaced by `tests/Concurrency/PublishProductConcurrencyTest.php`.
Both failure modes are written up in `how-to/troubleshooting.md`.
