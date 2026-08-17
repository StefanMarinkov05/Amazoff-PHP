# Actions

What exists in `app/Actions` today. Why they are written this way is
ADR-0007; how to add one is `how-to/add-an-action.md`; what each one does when
two of them run at once is `reference/write-rules/product.md`,
`reference/write-rules/cart.md`, `reference/write-rules/coupon.md`, and
`reference/write-rules/order.md`.

Twenty-one Actions across five areas, ten domain exceptions.

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
| `UpdateProduct` | `products` | optional, checked against `update_product` | `ProductRequiresVariationException`, `RemovedFromCatalogueException` |
| `DeleteProduct` | `products` (soft delete, cascaded to its variations) | optional, `delete_product` | — never refuses |
| `ForceDeleteProduct` | `products`, `product_variations`, `inventories`, `product_images`, `product_specifications` (all erased) | optional, `delete_product` | `ProductCannotBeErasedException`, plus whatever `ForceDeleteProductVariation` raises |
| `AddProductVariation` | `product_variations`, `inventories`, `inventory_movements` | optional, checked against `create_product_variation` | `InvalidArgumentException` |
| `RemoveProductVariation` | `product_variations` (soft delete) | optional, checked against `delete_product_variation` | `ProductRequiresVariationException`, `VariationHasReservedStockException` |
| `ForceDeleteProductVariation` | `product_variations`, `inventories` (both erased) | optional, checked against `delete_product_variation` | `VariationCannotBeErasedException`, `VariationHasReservedStockException`, `ProductRequiresVariationException` |
| `AddProductImage` | `product_images` | optional, `update_product` via `ProductImagePolicy` | `RemovedFromCatalogueException` |
| `SetMainProductImage` | `product_images` | optional, `update_product` | — |
| `RemoveProductImage` | `product_images`, and the file on disk | optional, `update_product` | `ProductImageInUseException` |

A product with images has exactly one main image, which MySQL cannot express —
no partial unique index, and ADR-0004 rejected triggers. `SetMainProductImage`
owns that rule in a single `UPDATE` (`is_main = (id = N)`), so it holds by
construction rather than by a lock; `AddProductImage` and `RemoveProductImage`
compose it. Images are not soft-deleted, and `RemoveProductImage` deletes the
file only after the transaction commits.

`ProductSpecification` has no Action: one table, no invariant, so ADR-0007
leaves it as default Filament CRUD.

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

## Cart

| Action | Writes | Actor | Throws |
|---|---|---|---|
| `AddToCart` | `cart_items` (insert or increment) | — no non-human caller, no parameter | `RemovedFromCatalogueException`, `InvalidCartQuantityException`, `InsufficientStockException` |
| `UpdateCartItemQuantity` | `cart_items.quantity` | — no non-human caller, no parameter | same three |
| `MergeGuestCart` | `cart_items`, deletes the guest `carts` row | — no non-human caller, no parameter | — never refuses |
| `RemoveFromCart` | `cart_items` (hard delete) | — no non-human caller, no parameter | — never refuses |

None of the four take an `?User $actor`. A customer editing their own cart
holds no permission to check, and nothing here has a non-human caller the
way `RecordInventoryMovement` or `ReserveStock` do — ADR-0007's stated
exception, not an oversight.

`AddToCart` and `UpdateCartItemQuantity` re-validate the line they are about
to write on every call — current price, availability, `min_order_quantity`,
stock — but never a line they are not touching. `MergeGuestCart` validates
nothing at all, deliberately: `CreateOrder` is what raises a stale merged
line, at checkout, not this Action. `reference/write-rules/cart.md`, "A line
after the catalogue changes underneath it" has the full table.

`AddToCart` and `MergeGuestCart` both lock nothing and instead catch
`UniqueConstraintViolationException` on `UNIQUE(cart_id,
product_variation_id)`, retrying as an update — CLAUDE.md's idempotency
rule, not ADR-0008's locking one, because a row that does not exist yet
cannot be locked. `MergeGuestCart`'s retry runs as a savepoint inside its
own outer transaction and must `lockForUpdate()` specifically on that retry;
`explanation/concurrency-and-locking.md` has why.

## Coupon

| Action | Writes | Actor | Throws |
|---|---|---|---|
| `ApplyCoupon` | `carts.coupon_id` | — no non-human caller, no parameter | `CouponNotApplicableException` |
| `RemoveCoupon` | `carts.coupon_id` (null) | — no non-human caller, no parameter | — never refuses |
| `RedeemCoupon` | `coupon_redemptions` | — no non-human caller, no parameter (decision 4) | `CouponNotApplicableException` |

`reference/write-rules/coupon.md` is the outcomes page.

`RedeemCoupon` re-validates everything `ApplyCoupon` checked, under
`lockForUpdate()` on the `coupons` row taken before either usage-cap
`COUNT` against `coupon_redemptions` — `coupons.times_used` does not exist;
both caps are counted rather than tracked on a column that would drift out
of sync with no constraint able to enforce the agreement. Same-order double
redemption is caught via `UNIQUE(coupon_id, order_id)` rather than checked
first, per CLAUDE.md's idempotency rule.

`ApplyCoupon` is default CRUD's opposite case from `RemoveCoupon`: it
qualifies for an Action on ADR-0007's second limb (an invariant the schema
cannot express — the window, minimum, and scope checks), while
`RemoveCoupon` rides on `RemoveFromCart`'s precedent rather than the test
itself, since a bare `UPDATE ... SET coupon_id = NULL` has no invariant of
its own. `CouponResource` stays default Filament CRUD — creating or editing
a `Coupon` row is single-table with no second writer, decision 10.

## Order

| Action | Writes | Actor | Throws |
|---|---|---|---|
| `CreateOrder` | `orders`, `order_items`, `order_addresses`; composes `RedeemCoupon` and `ReserveStock` | optional, recorded as `orders.user_id` — never inferred from a matching email | `EmptyCartException`, `CouponNotApplicableException`, `InsufficientStockException` |

`reference/write-rules/order.md` is the outcomes page.

Recomputes `subtotal_amount`/`vat_amount`/`total_amount` from the cart's
current contents — the signature carries no total input at all, so §28's
"never trust the browser total" is structural rather than an added check.
Snapshots product and variation data onto each `order_items` row (§19);
`discount_amount` there is always `'0.00'`, since the coupon discount is
an order-level deduction, never a per-line rewrite. `serial_number` is
derived from the row's own auto-increment id after insert, updated inside
the same transaction before commit — no dedicated counter, no extra lock.
No pre-order stock hold either: stock is reserved once, at order creation.

Every order is created at `OrderStatus::New`, `PaymentStatus::Pending`,
regardless of payment method — `TransitionOrderStatus` does not exist yet,
so the `New => AwaitingPayment`/`=> Confirmed` move is a later Action's job.

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
| `MergeGuestCart` | yes — plus one nested savepoint per line |
| `AddToCart` | yes, inside `addOrIncrement()` — not around the retry itself |
| `UpdateCartItemQuantity` | no — a single-row `UPDATE` needs nothing else |
| `RemoveFromCart` | no — a single `DELETE` |
| `RedeemCoupon` | yes — wraps the lock, both usage-cap counts, and the insert |
| `ApplyCoupon` | no — a single-row `UPDATE`, no lock to hold open |
| `RemoveCoupon` | no — a single-row `UPDATE` |
| `CreateOrder` | yes — wraps the order, items, addresses, `RedeemCoupon`, and every `ReserveStock` call |
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

`RedeemCoupon` takes `lockForUpdate()` on the `coupons` row before either
usage-cap `COUNT` against `coupon_redemptions` — no `CHECK` can span the
two tables, so without the lock two concurrent redemptions both read the
pre-redemption count and both insert. Declared lock order is `products`,
then `coupons`, then `inventories` (decision 5).

`CreateOrder` is the first Action to actually exercise part of that order:
it composes `RedeemCoupon` (locks `coupons`) before `ReserveStock` (locks
`inventories`, one row per line, sorted by `product_variation_id`), but
takes no `products` lock itself — nothing it writes touches `products`.
The sort's own correctness is unverified by any test: `cart_items`'s
`UNIQUE(cart_id, product_variation_id)` index happens to return rows
already sorted by `product_variation_id` for this query shape on the
current MySQL version, so removing the explicit sort does not turn any
test red. Kept anyway rather than relying on that unstated access path.
`reference/write-rules/order.md`, "Known gaps" has the full reasoning.

Duplicate SKUs and slugs are safe by the `UNIQUE` constraints from ADR-0005
rather than by anything in the Actions. A losing insert raises
`QueryException` rather than a validation error.

**What is still unprotected:** two employees saving product forms opened at
the same time silently revert each other's untouched fields. The window is
human think time, which no lock can span — a PHP request ends when the form
renders. Closing it needs optimistic concurrency, which ADR-0008 defers.
Measured and pinned, including the wrong behaviour, in
`tests/Feature/Actions/Catalogue/ConcurrentProductEditTest.php`.

`reference/write-rules/concurrency.md` is the full map.

## Exceptions

| Exception | Raised by | Carries |
|---|---|---|
| `InsufficientStockException` | `ReserveStock`, `AddToCart`, `UpdateCartItemQuantity` | the variation, requested quantity, available quantity |
| `ProductRequiresVariationException` | `CreateProduct`, `UpdateProduct`, `RemoveProductVariation`, `ForceDeleteProductVariation` | the product, when there is one |
| `VariationHasReservedStockException` | `RemoveProductVariation`, `ForceDeleteProductVariation` | the variation, the reserved quantity |
| `VariationCannotBeErasedException` | `ForceDeleteProductVariation` | the variation |
| `RemovedFromCatalogueException` | `ReserveStock`, `AddProductVariation`, `UpdateProduct`, `AddProductImage`, `AddToCart`, `UpdateCartItemQuantity` | the record that was removed |
| `ProductCannotBeErasedException` | `ForceDeleteProduct` | the product |
| `ProductImageInUseException` | `RemoveProductImage` | the image, the variation count |
| `InvalidCartQuantityException` | `AddToCart`, `UpdateCartItemQuantity` | the product, the quantity that was refused |
| `CouponNotApplicableException` | `ApplyCoupon`, `RedeemCoupon` | the coupon; six named constructors, one per refusal reason |
| `EmptyCartException` | `CreateOrder` | the cart |

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

Nine of the ten extend `RuntimeException`. `InvalidCartQuantityException`
extends `InvalidArgumentException` instead — deliberately, per its own
docblock: a bad cart quantity is "the caller passed a bad argument," not "a
domain rule a legal argument happened to violate." The same reasoning is why
a bare `InvalidArgumentException` (no domain subclass) covers a negative
quantity or an over-large release in `ReserveStock`/`ReleaseStock`.

That reasoning predates `ReportsDomainFailures`, which originally caught
`RuntimeException` only — `InvalidCartQuantityException` would have reached
a Filament page as an uncaught exception rather than a notification, unlike
its seven siblings, the moment a Cart Action got a caller that used the
trait. Fixed by widening the trait rather than changing the exception's base
class: `catch (RuntimeException|InvalidArgumentException $e)`, since the
`App\Exceptions\*` namespace check immediately after is what actually does
the domain-vs-defect filtering — the catch type only has to name every base
class a domain exception uses. `tests/Feature/Filament/ReportsDomainFailuresTest.php`
covers both, plus that a non-domain exception of either base class and a
`QueryException` still pass through uncaught.

## Callers

| Action | Called from |
|---|---|
| `CreateProduct`, `UpdateProduct` | `CreateProduct` / `EditProduct` pages, tests |
| `DeleteProduct`, `ForceDeleteProduct` | `EditProduct` header actions, tests |
| `AddProductVariation`, `RemoveProductVariation`, `ForceDeleteProductVariation` | `ProductVariationsRelationManager`, tests |
| `ReserveStock`, `ReleaseStock`, `RecordInventoryMovement` | composed by the above, tests |
| `AddToCart`, `UpdateCartItemQuantity`, `MergeGuestCart`, `RemoveFromCart` | tests only |
| `ApplyCoupon`, `RemoveCoupon` | tests only |
| `RedeemCoupon` | composed by `CreateOrder`, tests |
| `CreateOrder` | tests only |

`ProductResource` routes every write through its Action, per ADR-0007. §37
criterion 1 is met for the panel. The Cart, Coupon, and Order Actions have
no caller outside tests yet. `RedeemCoupon` now has the caller it was
designed for — `CreateOrder` composes it — even though `CreateOrder`
itself still has no caller beyond tests.

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

`reference/write-rules/concurrency.md` records which specific test covers each.

The two failure modes that make a guard test pass while proving nothing — an
exception raised by a nested Action, and fault injection on the connection
holding the lock — are written up in `how-to/troubleshooting.md`.
