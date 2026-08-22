# Order writes — expected behaviour

What happens when a cart becomes an order, alone and under concurrency.
Facts as of 2026-08-18, measured against the running stack rather than
read off the code.

Why the mechanisms differ is `explanation/concurrency-and-locking.md`. This
page is the outcomes, in the same shape as its siblings,
`reference/write-rules/cart.md` and `reference/write-rules/coupon.md`.

## What enforces any of this

`App\Actions\Order\CreateOrder`, and nothing else. Direct Eloquent, a
factory, a seeder, or a raw query builder all bypass every rule below.

## One actor at a time

| Refused when | Exception |
|---|---|
| Cart has no priceable line — empty, or every line points at a soft-deleted variation/product `CalculateCartTotals` already excludes | `EmptyCartException` |
| Applied coupon no longer applies — disabled, outside window, below minimum, out of scope, either usage cap reached | `CouponNotApplicableException` |
| Applied coupon's row is gone entirely (see "Known gaps" for why this is defence in depth rather than an open door) | `CouponNotApplicableException::noLongerExists()` |
| A line's stock is insufficient at reservation time | `InsufficientStockException` |
| Either address's `source_address_id` does not belong to the checking-out actor (or is given by a guest at all, who has no saved addresses to own) | `ModelNotFoundException` |
| The cart was already converted to an order — a double-submitted checkout, a retried job | `CartAlreadyCheckedOutException` |
| The checking-out actor's account no longer exists at all — a hard delete, not the ordinary soft delete | `CheckoutActorRemovedException` |

Every guard runs inside `CreateOrder`'s own transaction (the coupon and
stock checks) or before it opens (the empty-cart check, which has nothing
to roll back). A refusal at any point leaves **no partial order** — no
`orders` row, no `order_items`, no `order_addresses`, no
`coupon_redemptions` row, no reservation. Verified directly: `CreateOrderTest`,
"refuses and writes nothing when the coupon became invalid," "refuses and
writes nothing when stock runs out mid-transaction."

Nothing here authorizes anything — a customer placing their own order
holds no permission to check, and `OrderPolicy::create()` returns `false`
unconditionally (nobody creates an order through the admin panel; it exists
because a customer completed checkout).

## What gets written

| Table | What |
|---|---|
| `orders` | One row. `status = New`, `payment_status = Pending`, regardless of payment method. `cart_id` set from the checking-out cart — `UNIQUE`, nullable, no foreign key (see "Two actors at once" and the migration's own docblock). `serial_number` derived from the row's own auto-increment id (`ORD-%06d`), written in a second update inside the same transaction, before commit — no dedicated counter, no extra lock. `subtotal_amount`/`vat_amount`/`total_amount` recomputed from the cart's current contents; nothing from a caller is trusted (§28, obligation 3) — the signature carries no total input at all, so there is no field to bypass through. |
| `order_items` | One row per priceable cart line. Snapshots `product_name`, `product_sku`, `variation_name` (joined from the variation's attribute values, falling back to the SKU if it has none), `unit_price`, `line_total`, `vat_rate`, `vat_amount` — frozen at creation, immune to a later product edit (§19). `discount_amount` is always `'0.00'`: the coupon discount is an order-level deduction, never a rewrite of line prices. |
| `order_addresses` | Two rows, `UNIQUE(order_id, type)` — one `billing`, one `delivery`. A given `source_address_id` is scoped to the actor the same way CLAUDE.md requires everywhere else (`$actor->addresses()->findOrFail($id)`) rather than trusted as-is; a guest has no saved addresses to own, so any `source_address_id` from a guest is refused the same way. Verified: `CreateOrderTest`, "accepts a source_address_id that belongs to the checking-out actor," "refuses a source_address_id that belongs to another user," "refuses any source_address_id from a guest." |
| `coupon_redemptions` | One row, only if `cart.coupon_id` was set, written by `RedeemCoupon` — re-validated independently, nothing trusted from the cart's provisional state. |
| `inventories` / `inventory_movements` | Reserved for every line, via `ReserveStock`. |

Every line's price is resolved exactly once, before the order header and
before any `order_items` row, and that single resolution is reused for
both — not re-derived separately for the header total and for each line.
Two independent resolutions of "current price" for the same variation, one
for the header and one per line, could legitimately disagree if a price
changes in the narrow window between them, with nothing adversarial
required — an ordinary admin price edit landing at the wrong moment is
enough to produce an order whose own header does not sum to its own lines.
Not a race: the same hazard exists single-process, between two sequential
reads a few lines apart.

## What does not get written

- **`orders.user_id`** — set only from an authenticated `$actor`, never
  inferred by matching the given email against an existing account. A
  guest checkout is always `user_id = null`, even when the typed email
  matches a real account. Linking would attribute the guest's own name,
  phone, and delivery address into a stranger's order history the moment
  that account holder logs in — a cross-account PII leak, not merely an
  account-enumeration question. Verified: `CreateOrderTest`, "leaves
  user_id null for a guest checkout even when the email matches an
  existing account."
- **`carts.coupon_id` / `cart_items`** — the cart is not cleared after the
  order is created; `CreateOrder`'s own job ends at a correctly created
  order. Verified: `CreateOrderTest`, "leaves the cart itself and its
  items in place afterward."
- **A pre-order stock hold.** Stock is reserved once, at order creation,
  rather than held provisionally earlier and transferred. Zero hold
  window, zero abuse surface.
- **`OrderStatus::New => AwaitingPayment` / `=> Confirmed`** —
  `TransitionOrderStatus` does not exist yet. Every order is created at
  `New` regardless of payment method. CLAUDE.md's "COD reserves stock on
  confirmation" is read against this as a conservative superset, not a
  gap: reserving at creation is never later than reserving on confirmation
  would be.

## What changes underneath checkout

| Change | Outcome | Evidence |
|---|---|---|
| One of several lines has its variation/product soft-deleted between add-to-cart and checkout | that line is silently dropped; the order proceeds with the surviving lines only — same precedent `CalculateCartTotals` already sets for a rendered subtotal, inherited here unchanged | `CreateOrderTest`, "proceeds with the surviving lines when one of several is soft-deleted mid-checkout" |
| A variation is force-deleted while a cart line still references it | cannot happen — `ForceDeleteProductVariation` refuses erasure while any `cart_items` row depends on the variation, so this never reaches `CreateOrder` | `reference/actions.md`, Catalogue |
| The coupon's value is raised after being applied, past what the cart can absorb | the discount is capped at the matched subtotal, same as any other coupon re-validation — the order never goes negative | `CreateOrderTest`, "caps the order discount at the matched subtotal" |
| The cart row itself is deleted mid-checkout or afterward | no effect — every cart line needed is read into memory before the transaction opens, and `order_items` has no foreign key to `carts` at all, so nothing downstream depends on the cart surviving | safe by construction; `CreateOrderTest`, "leaves a placed order intact after its source cart is deleted" confirms the after-the-fact case empirically |
| The acting account is soft-deleted moments before or during checkout | the order is still created and correctly attributed — a soft delete is an update, not a row removal, so the `orders.user_id` foreign key is satisfied exactly as an order can reference a since-soft-deleted product | `CreateOrderTest`, "attributes the order to the actor even if that account was soft-deleted moments earlier" |
| The same person checks out once as a guest and once logged in, in parallel | the per-customer coupon cap unifies them correctly only if both checkouts used the same email — the cap is keyed by a hash of the order's own email, not by `user_id`, so identity here is whatever email was typed, not the account | mechanism shared with the per-customer race below; not separately tested, since the guard is blind to `user_id` by construction |

## Two actors at once

| Race | Outcome | Evidence |
|---|---|---|
| Two customers checking out the last unit of the same variation | exactly one order succeeds; the loser gets a clean `InsufficientStockException`, not a `QueryException` — no half-written order | `CreateOrderConcurrencyTest` |
| Two customers redeeming a coupon at its total usage limit, through `CreateOrder` | exactly one order succeeds; the loser gets a clean `CouponNotApplicableException` — proves composing `RedeemCoupon` inside `CreateOrder`'s larger transaction does not weaken the guarantee `RedeemCoupon` already proves alone | `CreateOrderConcurrencyTest` |
| The same cart checked out twice at once — a double-submitted "place order," or two tabs | exactly one order succeeds; the loser gets a clean `CartAlreadyCheckedOutException`, not a raw `QueryException` | `CreateOrderConcurrencyTest`, "fails the loser of a double-submitted checkout cleanly, producing exactly one order" |

All three share the same assertion shape: a constraint makes exactly one
winner certain regardless of whether the application-level guard holds
(`chk_inventories_reserved_not_above_current` for the first,
`coupon_redemptions`'s `UNIQUE(coupon_id, order_id)` for the second,
`orders.cart_id`'s `UNIQUE` for the third), so what the test proves is not
the winner count but *how* the loser fails — a handled domain exception
rather than a raw `QueryException` reaching the database. All three losers'
failures roll back the whole order and its items, not only the one row the
underlying constraint sits on.

## Lock order

`coupons` (via `RedeemCoupon`) before `inventories` (via `ReserveStock`,
one row per line, **sorted by `product_variation_id`**). No `products`
lock: nothing here writes `products`, and price/VAT are read
current-at-computation-time the same way `AddToCart` already does.

## Known gaps

**1. The deadlock-prevention sort is unverified by any test — deliberately,
not by oversight.** `cart_items`'s own `UNIQUE(cart_id,
product_variation_id)` index happens to return rows pre-sorted by
`product_variation_id` for a plain `WHERE cart_id = ?` query on the current
MySQL version, which makes the explicit `sortBy()` in `CreateOrder`
indistinguishable from a no-op in a live test — removing it, deliberately,
to check, did not turn any test red. It stays in the code anyway:
correctness should not depend on an unstated storage-engine access path
that a different index, a wider query, or a MySQL upgrade could change.
The fix is structural rather than something a race test demonstrates: the
window between locking two rows inside one transaction is milliseconds,
too narrow for a barrier-based test to force reliably without being flaky.

**2. `shipping_amount` is hardcoded to `'0.00'`.** No delivery-price
calculation exists yet to feed it.

**Resolved, previously listed here:** the same cart being checked out twice
(`orders.cart_id`, `UNIQUE`, nullable — see "Two actors at once"); a
hard-deleted actor reaching the database uncaught
(`CheckoutActorRemovedException`); a coupon hard-deleted between apply and
redemption being treated as no coupon at all
(`CouponNotApplicableException::noLongerExists()` — worth noting this one is
defence in depth more than an open door: `carts.coupon_id` is a
`constrained()` foreign key with no cascade, so an ordinary
`$coupon->forceDelete()` while any cart still applies it already fails at
the database with error 1451, discovered while writing the test for this
fix. `CreateOrderTest`, "refuses and writes nothing when the applied coupon
was hard-deleted before checkout" constructs the state by disabling FK
checks around the delete, the same technique the concurrency suites use for
truncation, precisely because the ordinary path is already closed).

## Status transitions

`App\Actions\Order\TransitionOrderStatus`, the only writer of `orders.status`
(ADR-0004). Direct Eloquent, a factory, a seeder, or a raw query builder all
bypass every rule below, same caveat as order creation.

### One actor at a time

| Refused when | Exception |
|---|---|
| The move is not in `OrderStatus::allowedTransitions()` from the order's current status | `IllegalOrderStatusTransitionException` |
| The actor lacks the permission the target status routes to — `cancel_order` for `Cancelled`, `refund_order` for `Refunded`, `updateStatus_order` otherwise (ADR-0011) | `Illuminate\Auth\Access\AuthorizationException` |

Legality is checked before authorization: a nonsense move is refused
regardless of who asked, and checking it first means a denial does not leak
whether the actor would otherwise have been permitted. A refusal writes
nothing — no status change, no history row, no inventory movement, no event.

### The no-op

`$from === $to` — a double-submitted status action, a retried job — returns
the order unchanged: no status write, no history row, no inventory movement,
no event. Checked *after* authorization, not before: a denied actor sees
`AuthorizationException` even when the move happens to already be done,
never a silent success that would leak whether it would otherwise have been
permitted.

Safe only because `OrderStatus`'s transition graph is acyclic
(`tests/Unit/Enums/TransitionMatrixTest.php`, "keeps the order status graph
acyclic") — no order can legitimately re-enter a status it already left, so
`from === to` is unambiguous evidence of a repeat rather than a status that
sometimes means "done" and sometimes means "here again, deliberately."

### What gets written

| Table | What |
|---|---|
| `orders` | `status` updated to the target. Nothing else on the row changes. |
| `order_status_histories` | One row: `previous_status`, `new_status`, `user_id` (null for a system actor), `reason`, `note` — the full §19 set. `UNIQUE(order_id, new_status)` backstops the `orders` lock, the same relationship `chk_inventories_reserved_not_above_current` has to `ReserveStock`'s lock — reaching the constraint means the lock failed. |
| `inventories` / `inventory_movements` | Only for the three targets in the effect table below; every other target writes none. |

### The inventory effect

Keyed by the **target** status, not the source — applied to every order
line, sorted by `product_variation_id` (the same sort `CreateOrder` applies,
for the same deadlock-avoidance reason):

| Target | Effect | Per line |
|---|---|---|
| `Cancelled` | `ReleaseStock` | reserved → available |
| `Shipped` | `CompleteSale` | reserved → sold |
| `Returned` | `RestockReturn` | sold → available, counted as a return |
| everything else | none | — |

Lives inside `TransitionOrderStatus` itself rather than a `CancelOrder`/
`ShipOrder` wrapper — ADR-0011's departure from ADR-0004's original
illustrative example, made explicit rather than silently diverged from. A
wrapper is bypassed by calling `TransitionOrderStatus` directly, which still
compiles and still passes every check the wrapper would have owned; folding
the effect into the Action that cannot be bypassed without bypassing the
status write itself closes that structurally rather than by convention.

Cancellation needs no "was stock actually reserved?" branch and has none:
`OrderStatus::allowedTransitions()` does not permit `Cancelled` from
`Shipped` or `Delivered`, so every reachable cancellation is from a status
where stock is reserved and not yet sold.

### Two actors at once

| Race | Outcome | Evidence |
|---|---|---|
| Two staff sending the same order to the same target at once | both calls report success — the second is the no-op above, not a race loser, since the lock serializes the second read onto the first one's already-written state | `TransitionOrderStatusConcurrencyTest`, "makes two identical concurrent transitions idempotent" |
| Two staff sending the same order to two different, mutually-exclusive targets at once | exactly one wins; the loser re-reads the locked row, finds its own target no longer legal from the winner's landing status, and gets a clean `IllegalOrderStatusTransitionException` | `TransitionOrderStatusConcurrencyTest`, "fails the loser of two different concurrent transitions cleanly" |

The second race deliberately does not use `Cancelled` as either side.
`Cancelled` is reachable from almost every non-terminal status (§20 lets an
order be cancelled from nearly anywhere), so a pairing that includes it is
not reliably mutually exclusive: whichever side "loses" the row lock can
often still reach `Cancelled` legally afterward, from the winner's landing
status, which would make the outcome non-deterministic rather than proving
anything. `AwaitingPayment` and `Confirmed`, both legal from `New` and
neither reachable from the other, is the pairing that is.

### Known gaps

**1. Calling `TransitionOrderStatus` directly for a target with an inventory
effect is the only way to route around that effect, and nothing stops it.**
There is no `CancelOrder` wrapper to bypass — see "The inventory effect"
above — but the flip side is that nothing distinguishes "a deliberate
`=> Cancelled` call" from "a caller that meant something else and got the
release for free." Convention and review are what hold this, the same
exposure ADR-0007 already records for a null actor.

**2. A returned item defaults to resellable, and marking it otherwise is a
separate, later, manual step — deliberately, not a shortcut waiting to be
closed.** `RestockReturn` always credits `current_quantity` on
`=> Returned`; `App\Actions\Inventory\RecordDamage` is what corrects that,
moving `current_quantity` to `damaged_quantity` — but it is not wired into
`TransitionOrderStatus`'s effect table, and nothing calls it automatically.

The two-step shape was considered and kept on purpose: the only source for
"is this actually damaged" at the moment an order moves to `Returned` is
whatever the customer typed into a return request, and that is unverified
input describing physical state the system cannot check — the same
category CLAUDE.md already rules out everywhere else ("the browser total is
never trusted," "a customer cannot order more than is available"). Folding
a resellable/damaged decision into the status transition would mean an
inventory movement — and the counters every other Action in this codebase
treats as ground truth — driven by a claim nobody verified. §20's own
movement vocabulary supports the split independently: `customer_return` and
`damaged_product` are two distinct types, not one, which reads as the spec
expecting them to be separate events rather than two faces of a single
decision.

So the return is received first (`RestockReturn`, unconditional, stock
nominally available again), and only a warehouse employee's physical
inspection — a `RecordDamage` call carrying its own authorized actor, never
anything sourced from the customer's stated reason — can move it to
`damaged_quantity` afterward. What is still genuinely missing is narrower
than "handle damaged returns": an authorized surface for staff to make that
call at all. Needs `OrderResource` or an inventory-correction screen,
neither built yet (slice 6b).

**3. Nothing in `TransitionOrderStatus` re-validates that the order's own
`order_items` still reference live `ProductVariation` rows** beyond loading
them `withTrashed()` to get a model to pass to the inventory Action. A
variation soft-deleted after the order shipped still has its `inventories`
row (the row deliberately outlives the variation, per §20 and
`ReserveStock`'s own docblock), so the counters move correctly regardless —
this is recorded because it is easy to assume otherwise, not because
anything is actually broken.
