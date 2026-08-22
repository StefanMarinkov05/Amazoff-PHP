# Actions

What exists in `app/Actions` today. Why they are written this way is
ADR-0007; how to add one is `how-to/add-an-action.md`; what each one does when
two of them run at once is `reference/write-rules/product.md`,
`reference/write-rules/cart.md`, `reference/write-rules/coupon.md`, and
`reference/write-rules/order.md`.

Twenty-six Actions across five areas, fourteen domain exceptions.

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
| `CompleteSale` | `inventories.reserved_quantity`, `inventories.current_quantity`, `inventories.sold_quantity`, `inventory_movements` | optional | `InvalidArgumentException` |
| `RestockReturn` | `inventories.sold_quantity`, `inventories.current_quantity`, `inventories.returned_quantity`, `inventory_movements` | optional | `InvalidArgumentException` |
| `RecordDamage` | `inventories.current_quantity`, `inventories.damaged_quantity`, `inventory_movements` | optional | `InvalidArgumentException` |

`RecordInventoryMovement` is the one Action that opens no transaction of its
own and is not meant to be called directly. A movement row without the
quantity change it describes is a false ledger entry, so the caller owns the
boundary.

`CompleteSale` and `RestockReturn` (ADR-0011) are the two counters §20's
schema always had and nothing wrote to until `TransitionOrderStatus` — moving
stock from reserved to sold on `=> Shipped`, and from sold back to current on
`=> Returned`. In `CompleteSale`, `reserved_quantity` is decremented before
`current_quantity`: `chk_inventories_reserved_not_above_current` is evaluated
per statement, and decrementing `current` first would fail it the moment a
sale empties stock that was fully reserved. `RestockReturn` has no such
ordering hazard — raising `current_quantity` can never violate that
constraint. Neither authorizes anything, for the same reason
`ReserveStock`/`ReleaseStock` do not: the caller has already authorized the
status change these record. `RestockReturn` assumes a return is resellable.

`RecordDamage` is the third such counter — `current_quantity` to
`damaged_quantity`, the `DamagedProduct` movement. General-purpose like
`ReserveStock`/`ReleaseStock` rather than composed by `TransitionOrderStatus`:
a warehouse employee marking N units damaged on the shelf is independent of
any specific order, and a damaged *return* is a separate, later call after
`RestockReturn` rather than a branch inside it — no admin surface triggers
either path yet. Guards `available()` (current minus reserved), not
`current_quantity` alone: damaging reserved stock would push
`reserved_quantity` above `current_quantity`, which the same `CHECK`
constraint rejects, and doing so silently would leave a reservation pointing
at stock that no longer exists.

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
| `DeleteProductCategory` | `product_categories` | optional, `delete_product_category` | `ProductCategoryCannotBeDeletedException` |

`ProductCategory` is otherwise default Filament CRUD, per CLAUDE.md's plain-
lookup-table exemption — `DeleteProductCategory` is the one exception, and a
narrow one: `parent_id`'s own `constrained()` foreign key already refuses a
delete while a subcategory exists, as a raw `QueryException`.
`ProductCategoryPolicy::delete()`'s own docblock had already named the gap
this closes — the same check lived in a Filament `visible()`/`disabled()`
closure, read once when the row rendered rather than when the click landed,
and `children()->count()` in it was commented out because `ProductCategory`
had no `children()` relation to call. Locks the category row before counting
live children and products, so both the storefront and the panel get the
same clean refusal instead of a 500 or a silently-disabled button.
`write-rules/product-category.md` is the outcomes page.

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
| `CreateOrder` | `orders`, `order_items`, `order_addresses`; composes `RedeemCoupon` and `ReserveStock` | optional, recorded as `orders.user_id` — never inferred from a matching email | `EmptyCartException`, `CouponNotApplicableException`, `InsufficientStockException`, `CartAlreadyCheckedOutException`, `CheckoutActorRemovedException` |
| `TransitionOrderStatus` | `orders.status`, `order_status_histories`; composes `ReleaseStock`/`CompleteSale`/`RestockReturn` by target status | optional, routed by `OrderPolicy::updateStatus()` on the target status (ADR-0011) | `IllegalOrderStatusTransitionException` |

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

`orders.cart_id` (`UNIQUE`, nullable, no foreign key) is set on the same
insert. `CartAlreadyCheckedOutException` is a caught
`UniqueConstraintViolationException`, not a check-then-act guard — the same
CLAUDE.md idempotency shape as `coupon_redemptions`'s
`UNIQUE(coupon_id, order_id)`. `CheckoutActorRemovedException` is a caught
`QueryException` on the `orders_user_id_foreign` constraint, distinguished by
message from any other `QueryException` that insert could raise — reachable
only if `$actor` is hard-deleted between being read and the insert.
`CouponNotApplicableException::noLongerExists()` is thrown before the
transaction opens if `cart.coupon_id` points at a row `Coupon::query()->find()`
no longer returns; `carts.coupon_id`'s own foreign key already blocks the
ordinary path to that state (no cascade, so a coupon cannot be hard-deleted
while any cart still applies it), so this is defence in depth rather than a
reachable production gap.

Every order is created at `OrderStatus::New`, `PaymentStatus::Pending`,
regardless of payment method. `CreateOrder` does not write an
`order_status_histories` row for that initial state — §19 requires history
for *changes*, and `null => New` is not one; the column stays nullable for a
future data import where the previous status is genuinely unknown.

`TransitionOrderStatus` (ADR-0004, ADR-0011) is the only writer of
`orders.status`. `OrderStatus::canTransitionTo()` decides legality;
`OrderPolicy::updateStatus(User, Order, OrderStatus $to)` decides who, routed
by target — `cancel_order`/`refund_order` for `Cancelled`/`Refunded`,
`updateStatus_order` otherwise, since ADR-0004's own context names
cancelling and refunding as administrator moves distinct from a warehouse
employee's routine advance. `$from === $to` is a clean no-op — no status
write, no history row, no inventory movement, no event — safe only because
`OrderStatus`'s transition graph is acyclic
(`tests/Unit/Enums/TransitionMatrixTest.php`, "keeps the order status graph
acyclic"). `UNIQUE(order_id, new_status)` on `order_status_histories`
backstops the `orders` row lock the same way
`chk_inventories_reserved_not_above_current` backstops `ReserveStock` —
reaching it means the lock failed.

The inventory consequence of a transition lives inside
`TransitionOrderStatus` itself, keyed by target status, rather than in a
`CancelOrder`/`ShipOrder` wrapper a caller could bypass by calling this
Action directly — ADR-0011's departure from ADR-0004's original
`CancelOrder` example. `=> Cancelled` releases every line
(`ReleaseStock`, unconditionally — the matrix does not allow `Cancelled`
from `Shipped` or `Delivered`, so every reachable cancellation is from a
state where stock is reserved and not yet sold); `=> Shipped` completes the
sale (`CompleteSale`, reserved → sold); `=> Returned` restocks
(`RestockReturn`, sold → current). Every other target moves no inventory.
Lines are processed sorted by `product_variation_id`, matching `CreateOrder`'s
own reasoning for the same deadlock-avoidance sort.

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
| `TransitionOrderStatus` | yes — wraps the status write, the history row, and every composed inventory Action |
| `CompleteSale` | yes |
| `RestockReturn` | yes |
| `RecordDamage` | yes |
| `DeleteProductCategory` | yes |
| `RecordInventoryMovement` | no |

Nesting is by savepoint, so the outermost boundary commits.
`RecordInventoryMovement` is the exception: it writes one row and is never the
whole operation, so the caller owns the boundary and it joins one.

`UpdateProduct` writes a single row and would not need a transaction for
atomicity. It opens one because `lockForUpdate()` outside a transaction
releases immediately and protects nothing.

### Panel-level transactions

`AdminPanelProvider::panel()` calls `->databaseTransactions()` — off by
default in Filament v4. Without it, a Filament Create/Edit page's record
save and its relationship sync are separate, individually-committed
statements: a `Select` field using `->relationship()->multiple()` (`CouponForm`'s
`products`/`productCategories`, `ProductForm`'s `attributes`, `RoleForm`'s
permission checklists) saves as a `detach()` of removed rows, then a
`sync()` of added ones — two statements, not one. A read that isn't inside
either statement (`CalculateCouponDiscount::forLines()`, called by
`ApplyCoupon`/`RedeemCoupon`, reads `coupon_product`/
`coupon_product_category` live and uncached) can land between them and see
neither the old nor the new eligible-product list. `databaseTransactions()`
wraps the whole page save in one `DB::transaction()`, closing this for
every resource at once rather than per-resource.

Writing a new Action needs no special handling for this. Nesting is by
savepoint (above), so an Action that opens `DB::transaction()` per the
table above behaves the same whether it's called from the storefront, from
inside a Filament page's now-open transaction, or from a test — open one
(or don't) exactly as the table says, and let savepoint nesting decide who
actually commits.

## Locking

`ReserveStock`, `ReleaseStock`, `CompleteSale`, `RestockReturn`, and
`RecordDamage` take `lockForUpdate()` on the inventory row before reading the
quantities. `explanation/concurrency-and-locking.md` covers what the lock
does and what the `CHECK` constraints do instead.

`DeleteProductCategory` takes `lockForUpdate()` on the `product_categories`
row before counting live children and products, so a subcategory or product
attached to it in the same instant is not read as a stale zero.
`parent_id`'s own foreign key backstops the outcome either way — no lock can
make a category-with-children delete succeed — so what the lock decides is
only whether the refusal is the clean domain exception or a raw
`QueryException`. Measured, not assumed, and with one honestly-recorded
limit: `tests/Concurrency/DeleteProductCategoryConcurrencyTest.php` could not
force the specific narrow window where removing the lock changes the
outcome, the same conclusion reached for the deadlock-prevention sort below.

`TransitionOrderStatus` takes `lockForUpdate()` on the `orders` row,
re-reading `status` from the locked row rather than trusting the model
passed into `handle()` — evaluating the parameter's own `status` instead of
the freshly-locked row's is a lock that protects nothing, and it is the one
subtlety here that no static check catches (`tests/Concurrency/
TransitionOrderStatusConcurrencyTest.php` proves it by deletion). Lock order
is **`orders` before `inventories`**: the composed inventory Action, if any,
locks its lines only after the `orders` lock is already held. No Action
today takes both in the opposite order.

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
| `CouponNotApplicableException` | `ApplyCoupon`, `RedeemCoupon`, `CreateOrder` | the coupon (nullable — `noLongerExists()` has none to carry); seven named constructors, one per refusal reason |
| `EmptyCartException` | `CreateOrder` | the cart |
| `CartAlreadyCheckedOutException` | `CreateOrder` | the cart |
| `CheckoutActorRemovedException` | `CreateOrder` | the actor |
| `IllegalOrderStatusTransitionException` | `TransitionOrderStatus` | the order, the `from` status, the `to` status |
| `ProductCategoryCannotBeDeletedException` | `DeleteProductCategory` | the category; two named constructors, `hasChildren()` and `hasProducts()` |

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

Thirteen of the fourteen extend `RuntimeException`. `InvalidCartQuantityException`
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
| `TransitionOrderStatus` | tests only — no `OrderResource` panel surface exists yet (slice 6b) |
| `CompleteSale`, `RestockReturn` | composed by `TransitionOrderStatus`, tests |
| `RecordDamage` | tests only — no caller composes it and no admin surface triggers it yet |
| `DeleteProductCategory` | `EditProductCategory` header action, tests |

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
