# Actions

What exists in `app/Actions` today. Why they are written this way is
ADR-0007; how to add one is `how-to/add-an-action.md`; what each one does when
two of them run at once is `reference/write-rules/product.md`,
`reference/write-rules/cart.md`, `reference/write-rules/coupon.md`, and
`reference/write-rules/order.md`.

Forty-three Actions across ten areas, twenty-six domain exceptions in `app/Exceptions`.

The Exceptions table below lists twenty-one of them. Five raised only by the Payment, Shipment, and ProductReview Actions — `IllegalPaymentStatusTransitionException`, `IllegalShipmentStatusTransitionException`, `PaymentAlreadyRecordedException`, `ReviewNotAllowedException`, `ShipmentNotAllowedException` — are documented in their own Action sections and have never been added here. Noted rather than left as a silent discrepancy between the count and the table.

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
| `RecordDamage` | `inventories.current_quantity`, `inventories.damaged_quantity`, `inventory_movements` | optional | `InsufficientStockToDamageException`, `InvalidArgumentException` |
| `AdjustStock` | `inventories.current_quantity`, `inventory_movements` | optional | `InvalidArgumentException` |

`AdjustStock` is the counterpart to `AddProductVariation`'s create-only
`initial_quantity` — a delivery arriving, or a stocktake correcting the
books, after a variation already exists. Takes a signed delta and a
`NewDelivery`/`ManualCorrection` type rather than an absolute "set stock to
N": both were already in `InventoryMovementType` since the schema was drawn
and neither had ever been written until this. Refuses a reduction that would
push `current_quantity` below what is already reserved, the same guard
`RecordDamage` uses and for the same reason — freeing reserved stock is
`ReleaseStock`'s decision about someone's order, not this Action's.

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
`RestockReturn` rather than a branch inside it. Both paths go through the
same admin surface, `ViewInventory`'s "Record damage" header action. Guards
`available()` (current minus reserved), not `current_quantity` alone:
damaging reserved stock would push `reserved_quantity` above
`current_quantity`, which the same `CHECK` constraint rejects, and doing so
silently would leave a reservation pointing at stock that no longer exists.
Exceeding `available()` throws `InsufficientStockToDamageException` rather
than `InvalidArgumentException` — unlike its three siblings above, this
Action is reached directly from a quantity a warehouse employee types into
the panel, where exceeding available stock is a mistake to correct rather
than a caller bug (ADR-0007). `ViewInventory` also disables the button and
caps the quantity field at `available()`, so the exception is normally a
race-condition backstop, not the primary guard.

## Catalogue

| Action | Writes | Actor | Throws |
|---|---|---|---|
| `CreateProduct` | `products`, `attribute_product`, `product_variations`, `inventories`, `inventory_movements` | optional, checked against `create_product` | `ProductRequiresVariationException`, `AttributeNotAllowedForCategoryException` |
| `UpdateProduct` | `products`, `attribute_product` (only when the caller supplies the key) | optional, checked against `update_product` | `ProductRequiresVariationException`, `RemovedFromCatalogueException`, `AttributeNotAllowedForCategoryException` |
| `DeleteProduct` | `products` (soft delete, cascaded to its variations) | optional, `delete_product` | — never refuses |
| `ForceDeleteProduct` | `products`, `product_variations`, `inventories`, `product_images`, `product_specifications` (all erased) | optional, `delete_product` | `ProductCannotBeErasedException`, plus whatever `ForceDeleteProductVariation` raises |
| `AddProductVariation` | `product_variations`, `inventories`, `inventory_movements`, `attribute_value_product_variation` (only when the caller supplies `attribute_value_ids`) | optional, checked against `create_product_variation` | `InvalidArgumentException` |
| `RemoveProductVariation` | `product_variations` (soft delete) | optional, checked against `delete_product_variation` | `ProductRequiresVariationException`, `VariationHasReservedStockException` |
| `ForceDeleteProductVariation` | `product_variations`, `inventories` (both erased) | optional, checked against `delete_product_variation` | `VariationCannotBeErasedException`, `VariationHasReservedStockException`, `ProductRequiresVariationException` |
| `AddProductImage` | `product_images` | optional, `update_product` via `ProductImagePolicy` | `RemovedFromCatalogueException` |
| `SetMainProductImage` | `product_images` | optional, `update_product` | — |
| `RemoveProductImage` | `product_images`, and the file on disk; gallery rows cascade | optional, `update_product` | — |
| `SetVariationImages` | `product_image_product_variation` (the whole set for 1 variation) | optional, `update_product_variation` | `RemovedFromCatalogueException`, `ImageNotOnProductException` |
| `SetProductAttributeValues` | `attribute_value_product` (the whole set for 1 product) | optional, `update_product` | `RemovedFromCatalogueException`, `AttributeNotAllowedForCategoryException`, `AttributeValueIsAVariationAxisException` |
| `SetVariationAttributeValues` | `attribute_value_product_variation` (the whole set for 1 variation) | optional, `update_product_variation` | `RemovedFromCatalogueException`, `AttributeValueNotOnProductException`, `DuplicateVariationAttributeException`, `DuplicateVariationCombinationException` |
| `SetDefaultVariation` | `product_variations` | optional, `update_product_variation` | — |
| `DeleteProductCategory` | `product_categories` | optional, `delete_product_category` | `ProductCategoryCannotBeDeletedException` |
| `UpdateProductCategory` | `product_categories` | optional, `update_product_category` | `CategoryCycleException` |

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

`UpdateProductCategory` is the second, for the same narrow reason: `parent_id` is a self-referencing foreign key and no foreign key can express acyclicity, so the database accepts A→B→A without complaint and every walk of the relationship — the storefront filter, the admin's ancestry breadcrumb, the mega-menu — then loops. Only the *edit* path needs it: a category being created has no descendants, so no choice of parent can close a loop through it, and `CreateProductCategory` stays on Filament's default create. Locks the category being moved before walking its descendants — without it, two administrators reparenting A under B and B under A concurrently each see a tree in which their own move is legal, and both commit.

A product with images has exactly one main image, which MySQL cannot express —
no partial unique index, and ADR-0004 rejected triggers. `SetMainProductImage`
owns that rule in a single `UPDATE` (`is_main = (id = N)`), so it holds by
construction rather than by a lock; `AddProductImage` and `RemoveProductImage`
compose it. Images are not soft-deleted, and `RemoveProductImage` deletes the
file only after the transaction commits.

The same shape exists one level down: a product has exactly one default
variation. `SetDefaultVariation` mirrors `SetMainProductImage` exactly — one
`UPDATE` (`is_default = (id = N)`), no lock. `AddProductVariation` composes
it to promote a product's first variation automatically, or a later one when
the caller passes `is_default: true`; `RemoveProductVariation` composes it to
hand the flag to a live sibling when the removed variation held it, so a
product with variations never ends up with none of them default.

`ProductSpecification` has no Action: 1 table, no invariant, so ADR-0007
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

`AddProductVariation` also composes `SetVariationAttributeValues` when the
caller supplies `attribute_value_ids` — a variation's own combination
(`docs/explanation/product-variability.md`'s first question, "what makes
this one different?") set in the same call that gives it a SKU and a stock
row, rather than a separate step an administrator has to remember. `null`,
not the actor: the same precedent as composing `SetDefaultVariation` below
it — the caller's own `create_product_variation` gate has already authorized
the whole write.

`CreateProduct` syncs `attribute_product` (the product's own variation axes)
itself, rather than leaving `ProductForm`'s "Variation axes" field to a
`->relationship()` Select's own post-save handling — deliberately, not by
oversight. A `->relationship()` field's state is excluded from the form's
`getState()` and saved by Filament's `CreateRecord::create()` **after**
`handleRecordCreation()` returns, which is too late: `AddProductVariation`
above validates each variation's `attribute_value_ids` against the product's
axes *during* this Action's own transaction. With `->relationship()`, every
variation given a combination was refused with
`AttributeValueNotOnProductException`, against a product that was about to
have exactly those axes one line later in the same request — reproduced
live before landing this fix, not assumed from reading the timing alone.
`UpdateProduct` syncs the same relationship for the same reason, on the edit
path; `EditProduct::mutateFormDataBeforeFill()` hydrates the field back on
open, which `->relationship()` would otherwise have done automatically.

Both also refuse an axis `attribute_product_category` does not allow for the
product's own category, or any of its ancestors —
`AttributeNotAllowedForCategoryException`. `attribute_product_category` is an
allow-list an admin opts an attribute into (`AttributeForm`'s "Allowed
categories" field); an attribute with no rows there is unrestricted, not
"allowed nowhere", so this cannot fire for any of the attributes that
existed before the table did. `App\Support\Resolvers\ResolveAllowedAttributes` is the
pure function both Actions call — a category's own allow-list, unioned with
every ancestor's, unioned with every unrestricted attribute.
`UpdateProduct` checks this **after** `save()`, using whichever category the
product has now: one call that moves a product into a new category and sets
an axis that category allows is not refused for a mismatch that was only
ever true before the save committed.

## Cart

| Action | Writes | Actor | Throws |
|---|---|---|---|
| `AddToCart` | `cart_items` (insert or increment) | — no non-human caller, no parameter | `RemovedFromCatalogueException`, `InvalidCartQuantityException`, `InsufficientStockException` |
| `UpdateCartItemQuantity` | `cart_items.quantity` | — no non-human caller, no parameter | same three |
| `MergeGuestCart` | `cart_items`, deletes the guest `carts` row | — no non-human caller, no parameter | — never refuses |
| `RemoveFromCart` | `cart_items` (hard delete) | — no non-human caller, no parameter | — never refuses |
| `ExpireCarts` | deletes `carts` past `expires_at` (and their `cart_items`, by cascade) | — no actor at all, human or otherwise: invoked by the `carts:expire` schedule | — never refuses |

None of the first four take an `?User $actor`. A customer editing their own
cart holds no permission to check, and nothing here has a non-human caller
the way `RecordInventoryMovement` or `ReserveStock` do — ADR-0007's stated
exception, not an oversight. `ExpireCarts` goes further: there is no actor
to check *against* — it runs on a schedule, not in response to anyone's
request — and it excludes any cart already referenced by `orders.cart_id`,
since that cart produced a real order and isn't abandoned. Nothing in
`app/` sets `expires_at` yet, so today this has nothing to act on; it
exists ahead of that TTL policy, not because of it.

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
| `CreateOrder` | `orders`, `order_items`, `order_addresses`; composes `RedeemCoupon`, `ReserveStock`, and `CalculateDeliveryPrice` (for `shipping_amount`/`carrier_id`, given a carrier) | optional, recorded as `orders.user_id` — never inferred from a matching email | `EmptyCartException`, `CouponNotApplicableException`, `InsufficientStockException`, `CartAlreadyCheckedOutException`, `CheckoutActorRemovedException` |
| `TransitionOrderStatus` | `orders.status`, `order_status_histories`; composes `ReleaseStock`/`CompleteSale`/`RestockReturn` by target status | optional, routed by `OrderPolicy::updateStatus()` on the target status (ADR-0011) | `IllegalOrderStatusTransitionException` |
| `RecordPayment` | `payments` | optional and **unauthorized by design** — `PaymentPolicy::create()` returns false outright; a payment exists because a customer checked out, never because someone pressed a button | `PaymentAlreadyRecordedException` |
| `TransitionPaymentStatus` | `payments.status`, `paid_at`, `refunded_amount` | optional, `update_payment` — except a refund, routed to `refund_payment` | `IllegalPaymentStatusTransitionException`, `InvalidArgumentException` |
| `CreateStripeIntent` | `payments.stripe_payment_intent_id` | **none** — the customer's own checkout path, where there is no permission to hold | `StripeIntentNotAllowedException` |
| `HandleStripeWebhookEvent` | `payment_events`; composes `TransitionPaymentStatus` | **none** — Stripe is the actor. The request is authenticated by `VerifyStripeWebhookSignature` middleware, not by a permission | — (refusals are logged and acknowledged, never thrown; see below) |
| `RefundPayment` | `payments.status`, `refunded_amount` via `TransitionPaymentStatus`; calls Stripe | optional, `refund_payment` | `RefundNotAllowedException`, `AuthorizationException` |
| `CreateShipment` | `shipments` | optional, `create_shipment` | `ShipmentNotAllowedException` |
| `TransitionShipmentStatus` | `shipments.status`, `shipped_at`, `delivered_at`, `raw_status`, `shipment_tracking_events` | optional, `update_shipment` | `IllegalShipmentStatusTransitionException` |
| `CreateProductReview` | `product_reviews` | the **reviewer**, required — ownership is proven by the purchase check, not a permission | `ReviewNotAllowedException` |
| `ApproveProductReview` | `product_reviews.approved` (`true`) | optional, `approve_product_review` | `AuthorizationException` |
| `UnapproveProductReview` | `product_reviews.approved` (`false`) | optional, `approve_product_review` — the same ability the other direction, §24; there is no separate `unapprove_product_review` permission | `AuthorizationException` |

`reference/write-rules/order.md` is the outcomes page.

The shipment Actions are the *domain* half of slice 8, deliberately split
from its connector: every courier column is nullable, so a shipment can be
opened, transitioned and reported on before any Saloon connector exists.
The connector half now exists (`App\Contracts\CourierGateway`,
`docs/explanation/couriers.md`), but nothing yet calls it from
`CreateShipment` — a future `DispatchShipment` Action, not built here, is
what would create the real vendor shipment and fill in the columns
`CreateShipment` currently leaves null.

`CreateOrder` calls `CalculateDeliveryPrice` (a `Support` function, not an
Action — it writes nothing) whenever it is given a carrier, resolving
`shipping_amount` and `orders.carrier_id` from it. See
`write-rules/order.md`'s "known gaps" #2.

The Stripe half is now built. `CreateStripeIntent` fills
`stripe_payment_intent_id` on a row `RecordPayment` opened rather than
creating its own, exactly as this page anticipated.

**`HandleStripeWebhookEvent` throws nothing, deliberately.** Every other
Action signals a refusal by throwing; this one logs and returns. Stripe
retries any non-2xx for up to three days, so throwing on a condition that
will never succeed — an event type this application ignores, an intent it
has never seen, an out-of-order delivery implying an illegal transition —
would buy nothing and generate days of identical retries. The refusal is
recorded in `payment_events.note` instead, which is also what makes the
panel's event table a reconciliation surface rather than a log dump.
`StripeWebhookController` reserves its own 500 for genuinely retryable
failures.

Two of them intentionally break the symmetry their siblings follow:

- **`TransitionPaymentStatus` has no `$from === $to` no-op**, unlike
  `TransitionOrderStatus`. `PaymentStatus` is cyclic —
  `PartiallyRefunded` lists itself — so a repeat is a second real refund and
  a shortcut would silently swallow it. The cost is losing free
  double-submit protection, which is why webhook idempotency must key on
  `payment_events.stripe_event_id` instead; `write-rules/order.md`'s known
  gap 3 covers what is still missing there.
- **`TransitionShipmentStatus` puts its no-op *before* the legality check**,
  the reverse of where you would expect it. A courier polling loop repeats
  `Delivered` constantly, and `Delivered` does not list itself, so checking
  legality first would raise on every poll. The no-op also appends no
  tracking event, which is what keeps the table from filling with duplicate
  "still in transit" rows.

`CreateProductReview` requires the reviewer rather than accepting a null
actor, unlike every other Action here: §24's verified-purchase rule has
nothing to check against without one, and `UNIQUE(user_id, product_id)` does
not constrain nulls in MySQL, so a guest could review the same product
indefinitely. A guest path needs its own rule and its own ADR.

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

## Content

| Action | Writes | Actor | Throws |
|---|---|---|---|
| `PublishArticle` | `articles.status`, `articles.published_at` | required — no non-human caller exists | `ArticleTransitionNotAllowedException` |

Below ADR-0007's usual bar for an Action — one column, 1 table — built
anyway because the authorization is the entire point. `publish_article` is a
permission distinct from `update_article` (`content_editor` holds both), so
the check has to be `publish`, not `update`; a `Select` on `status` in
`ArticleForm` would have checked the wrong ability and skipped
`ArticleStatus::canTransitionTo()` entirely. `status` and `published_at` are
absent from the form for exactly that reason.

`published_at` is set once, the first time an article reaches `Published`,
and never overwritten on a later transition — it answers "when did readers
first see this," not "when was this last touched," which `updated_at`
already covers. No transaction: one row, one statement, nothing read first
to decide anything.

## Contact

| Action | Writes | Actor | Throws |
|---|---|---|---|
| `SubscribeToNewsletter` | `newsletter_subscribers.status`, `.subscribed_at`, `.user_id` | optional — a guest subscribes with `null` | — |

Below ADR-0007's bar on the write itself — one row, one table — and built
anyway because `NewsletterSubscriberForm` also sets `status`. Two writers
decide it, and only this one knows that subscribing again reverses an
unsubscribe rather than failing. That second writer is the whole reason the
ADR's "no second writer" carve-out does not apply.

Idempotent through the UNIQUE index on `email` plus a caught violation, per
CLAUDE.md — never `exists()` then insert, which two simultaneous submissions
of one address both pass. The update on that path skips null values, so a
guest re-subscribing cannot blank a `user_id` an account already owns, while
a signed-in user claims a row they created as a guest. Without that, a
subscription made before registering stays unlinked and an erasure request
scanning by user never finds it — `explanation/gdpr.md` lists the table as
personal data.

Contact messages deliberately have no Action. One insert, one table, no
second writer: `ContactForm` calls `ContactMessage::create()` directly, which
is the same test that keeps the lookup tables on Filament's default CRUD.

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
| `RecordPayment` | yes — the `orders` lock and the existence check are the same window |
| `TransitionPaymentStatus` | yes — the refund cap is read and written inside one `payments` lock |
| `CreateShipment` | yes — same shape as `RecordPayment` |
| `TransitionShipmentStatus` | yes — wraps the status write and its tracking event |
| `CreateProductReview` | yes — though the guard is a caught `UNIQUE` violation, not a lock |
| `RecordInventoryMovement` | no |
| `SubscribeToNewsletter` | no - one row either way, and the UNIQUE index is what serialises it |

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
§6–7's invariant spans 2 tables, which MySQL cannot express as a constraint
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
2 tables, so without the lock two concurrent redemptions both read the
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
| `RemovedFromCatalogueException` | `ReserveStock`, `AddProductVariation`, `UpdateProduct`, `AddProductImage`, `SetVariationImages`, `AddToCart`, `UpdateCartItemQuantity` | the record that was removed |
| `ProductCannotBeErasedException` | `ForceDeleteProduct` | the product |
| `ImageNotOnProductException` | `SetVariationImages` | the variation, the offending image ids |
| `AttributeValueNotOnProductException` | `SetVariationAttributeValues` | the variation, the offending attribute-value ids |
| `DuplicateVariationAttributeException` | `SetVariationAttributeValues` | the variation, the attribute given two values |
| `DuplicateVariationCombinationException` | `SetVariationAttributeValues` | the variation, the sibling variation already holding the same combination |
| `AttributeNotAllowedForCategoryException` | `CreateProduct`, `UpdateProduct`, `SetProductAttributeValues` | the product, the offending attribute ids |
| `AttributeValueIsAVariationAxisException` | `SetProductAttributeValues` | the product, the clashing attribute |
| `CategoryCycleException` | `UpdateProductCategory` | the category, the proposed parent |
| `InvalidCartQuantityException` | `AddToCart`, `UpdateCartItemQuantity` | the product, the quantity that was refused |
| `CouponNotApplicableException` | `ApplyCoupon`, `RedeemCoupon`, `CreateOrder` | the coupon (nullable — `noLongerExists()` has none to carry); seven named constructors, one per refusal reason |
| `EmptyCartException` | `CreateOrder` | the cart |
| `CartAlreadyCheckedOutException` | `CreateOrder` | the cart |
| `CheckoutActorRemovedException` | `CreateOrder` | the actor |
| `IllegalOrderStatusTransitionException` | `TransitionOrderStatus` | the order, the `from` status, the `to` status |
| `ProductCategoryCannotBeDeletedException` | `DeleteProductCategory` | the category; two named constructors, `hasChildren()` and `hasProducts()` |
| `ArticleTransitionNotAllowedException` | `PublishArticle` | the `from` and `to` statuses, as `ArticleStatus` instances rather than strings |

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

Twenty of the twenty-one listed extend `RuntimeException`. `InvalidCartQuantityException`
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

### Bulk delete composes with the per-record Action

`DeleteBulkAction` is a second, independent call site Filament wires up by
default, and it calls `$record->delete()` directly — so routing a resource's
*single* delete through an Action does nothing for its bulk path. On a
soft-deleting model (`Product`) the bulk delete even succeeds silently,
skipping the Action's cascade with no error at all.

`App\Filament\Actions\DomainDeleteBulkAction` closes this. It replaces the
process closure (Filament v4's `DeleteBulkAction::using()` seam) with a loop
that calls the per-record delete Action for each selected row, catching only
`App\Exceptions\*` refusals — the same namespace filter
`ReportsDomainFailures` uses. Two entries land in the resource's
`BulkActionGroup`: `make()` (partial — delete what can be deleted, summarise
the rest) and `makeAtomic()` (all-or-nothing — one transaction, rolled back
if any row is refused). Applied to `Products`, `ProductCategories`, `Brands`,
`Attributes`, `ArticleCategories`, `Coupons`, `Carriers`; the ruleless
lookup-table resources keep the plain `DeleteBulkAction`. Regression coverage:
`tests/Feature/Filament/DomainDeleteBulkActionTest.php`.

## Callers

| Action | Called from |
|---|---|
| `CreateProduct`, `UpdateProduct` | `CreateProduct` / `EditProduct` pages, tests |
| `DeleteProduct`, `ForceDeleteProduct` | `EditProduct` header actions; `DeleteProduct` also from `ProductsTable`'s bulk delete via `DomainDeleteBulkAction`; tests |
| `AddProductVariation`, `RemoveProductVariation`, `ForceDeleteProductVariation` | `ProductVariationsRelationManager`, tests |
| `SetVariationAttributeValues` | composed by `AddProductVariation`; also called directly by `ProductVariationsRelationManager`'s edit action, tests |
| `ReserveStock`, `ReleaseStock`, `RecordInventoryMovement` | composed by the above, tests |
| `AddToCart`, `UpdateCartItemQuantity`, `MergeGuestCart`, `RemoveFromCart` | tests only |
| `ExpireCarts` | `carts:expire` console command (`routes/console.php`, scheduled daily), tests |
| `ApplyCoupon`, `RemoveCoupon` | tests only |
| `RedeemCoupon` | composed by `CreateOrder`, tests |
| `CreateOrder` | tests only |
| `TransitionOrderStatus` | tests only — no `OrderResource` panel surface exists yet (slice 6b) |
| `CompleteSale`, `RestockReturn` | composed by `TransitionOrderStatus`, tests |
| `RecordDamage` | `ViewInventory`'s "Record damage" header action, tests |
| `AdjustStock` | `ProductVariationsRelationManager`'s "Adjust stock" row action, tests |
| `DeleteProductCategory` | `EditProductCategory` header action; `ProductCategoriesTable` bulk delete via `DomainDeleteBulkAction`; tests |
| `UpdateProductCategory` | `EditProductCategory`, tests |
| `DeleteBrand`, `DeleteAttribute`, `DeleteArticleCategory`, `DeleteCoupon`, `DeleteCarrier` | their `Edit*` page header action, and their resource table's bulk delete via `DomainDeleteBulkAction`; tests |
| `SetProductAttributeValues` | `CreateProduct` / `EditProduct` pages, tests |
| `PublishArticle` | generated status-change menu on `ArticlesTable`, tests |
| `SubscribeToNewsletter` | `Contact\NewsletterSignup` (footer), tests |
| `ApproveProductReview`, `UnapproveProductReview` | `ProductReviewsTable`'s row actions and bulk "approve" action, tests — untested until 2026-09-06 despite the live panel surface |

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
authorization check on each of the 5 Actions, the actor passed down from
`CreateProduct`, the transaction on `CreateProduct` and `AddProductVariation`,
the inventory row, the required-variation rule at creation, the sellability
rule on update, the last-variation and reserved-stock guards on removal, the
two quantity guards, the `products` lock shared by `UpdateProduct` and
`RemoveProductVariation`, the two soft-delete guards, the gate ordering in
`AddProductVariation`, and the erase Action's delete order plus its ledger,
cart, and last-live-variation refusals.

`reference/write-rules/concurrency.md` records which specific test covers each.

The two failure modes that make a guard test pass while proving nothing — an
exception raised by a nested Action, written up in
`how-to/troubleshooting/ide-and-static-analysis.md`, and fault injection on
the connection holding the lock, in
`how-to/troubleshooting/concurrency-and-testing-races.md` — are written up
in the troubleshooting tree.
