# Order writes — expected behaviour

What happens when a cart becomes an order, alone and under concurrency.
Facts as of 2026-08-17, measured against the running stack rather than
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
| A line's stock is insufficient at reservation time | `InsufficientStockException` |

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
| `orders` | One row. `status = New`, `payment_status = Pending`, regardless of payment method. `serial_number` derived from the row's own auto-increment id (`ORD-%06d`), written in a second update inside the same transaction, before commit — no dedicated counter, no extra lock. `subtotal_amount`/`vat_amount`/`total_amount` recomputed from the cart's current contents; nothing from a caller is trusted (§28, obligation 3) — the signature carries no total input at all, so there is no field to bypass through. |
| `order_items` | One row per priceable cart line. Snapshots `product_name`, `product_sku`, `variation_name` (joined from the variation's attribute values, falling back to the SKU if it has none), `unit_price`, `line_total`, `vat_rate`, `vat_amount` — frozen at creation, immune to a later product edit (§19). `discount_amount` is always `'0.00'`: the coupon discount is an order-level deduction, never a rewrite of line prices. |
| `order_addresses` | Two rows, `UNIQUE(order_id, type)` — one `billing`, one `delivery`. |
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
| The cart row itself is deleted mid-checkout | no effect on an in-flight order — every cart line needed is read into memory before the transaction opens, and `order_items` has no foreign key to `carts` at all, so nothing downstream depends on the cart surviving | safe by construction, not by a guard |
| The acting account is soft-deleted moments before or during checkout | the order is still created and correctly attributed — a soft delete is an update, not a row removal, so the `orders.user_id` foreign key is satisfied exactly as an order can reference a since-soft-deleted product | `CreateOrderTest`, "attributes the order to the actor even if that account was soft-deleted moments earlier" |
| The same person checks out once as a guest and once logged in, in parallel | the per-customer coupon cap unifies them correctly only if both checkouts used the same email — the cap is keyed by a hash of the order's own email, not by `user_id`, so identity here is whatever email was typed, not the account | mechanism shared with the per-customer race below; not separately tested, since the guard is blind to `user_id` by construction |

## Two actors at once

| Race | Outcome | Evidence |
|---|---|---|
| Two customers checking out the last unit of the same variation | exactly one order succeeds; the loser gets a clean `InsufficientStockException`, not a `QueryException` — no half-written order | `CreateOrderConcurrencyTest` |
| Two customers redeeming a coupon at its total usage limit, through `CreateOrder` | exactly one order succeeds; the loser gets a clean `CouponNotApplicableException` — proves composing `RedeemCoupon` inside `CreateOrder`'s larger transaction does not weaken the guarantee `RedeemCoupon` already proves alone | `CreateOrderConcurrencyTest` |
| The same cart checked out twice at once — a double-submitted "place order," or two tabs | **both succeed. Two separate orders result, from one cart.** Not a guarantee — a gap, pinned deliberately so a future fix has a red test to turn green rather than a silent assumption | `CreateOrderConcurrencyTest`, "produces two orders from one cart checked out twice at once" |

The first two share the same assertion shape as `ReserveStockConcurrencyTest`,
not `RedeemCouponConcurrencyTest`'s in isolation:
`chk_inventories_reserved_not_above_current` plus `increment()` already
guarantee exactly one winner regardless of whether the lock holds. What
the lock decides is *how* the loser fails, and — new to this Action,
compared to `ReserveStock`/`RedeemCoupon` tested alone — that the loser's
failure rolls back the order and its items too, not only the reservation
or the redemption.

The third is a different shape entirely: nothing backstops it, nothing is
expected to, and the test says so in its own name rather than pretending
otherwise. See "Known gaps."

## Lock order

`coupons` (via `RedeemCoupon`) before `inventories` (via `ReserveStock`,
one row per line, **sorted by `product_variation_id`**). No `products`
lock: nothing here writes `products`, and price/VAT are read
current-at-computation-time the same way `AddToCart` already does.

## Known gaps

**1. No protection against the same cart being checked out twice.**
Confirmed by test, not assumed: two concurrent `CreateOrder` calls against
one cart both succeed, producing two orders (see "Two actors at once").
Nothing marks a cart as already converted — no `converted_to_order_id`
column, no lock taken on the cart itself, no idempotency key. Every other
write-once concern in this codebase is closed by a `UNIQUE` constraint
caught rather than checked (`AddToCart`'s collision retry,
`coupon_redemptions`'s `UNIQUE(coupon_id, order_id)`); this one has no
equivalent. Not fixed here — closing it is a design decision (a schema
column plus a guard, or a caller-supplied idempotency key once a checkout
endpoint exists to receive one), not a mechanical correction.

**2. Inserting `order_addresses.source_address_id` or `orders.user_id`
against a row that no longer exists reaches the database uncaught.**
Both columns are `nullOnDelete()`, which governs an *existing* child row
when its parent is removed — it does not stop a *new* insert from
referencing an id that is already gone. A hard-deleted `User` between
reading `$actor` and the `orders` insert, or a hard-deleted `Address`
between a customer selecting it and submitting checkout, surfaces as an
uncaught `QueryException` rather than a handled refusal. Confirmed by
test for the `user_id` case: `CreateOrderTest`, "surfaces an uncaught
QueryException when the actor account no longer exists at all." The
`Address` case is the same mechanism, unverified by a test of its own.
Both are narrow — `User` defaults to soft deletes, and a hard-delete path
for either model may not exist in the admin panel yet — but the gap is
real independent of how reachable it currently is.

**3. The deadlock-prevention sort is unverified by any test — deliberately,
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

**4. `shipping_amount` is hardcoded to `'0.00'`.** No delivery-price
calculation exists yet to feed it.

**5. A coupon hard-deleted between apply and redemption is treated as no
coupon at all, silently.** `Coupon::query()->find($cart->coupon_id)`
returns `null` if the row is gone, and `CreateOrder` reads that identically
to "no coupon was ever applied" — the order proceeds at full price with no
discount and no error, rather than a refusal telling the customer their
discount is gone. Every other coupon invalidation (disabled, expired,
capped) raises `CouponNotApplicableException`; a hard-deleted coupon is the
one path that does not, because there is no row left to carry the refusal
context a named exception constructor needs. Not fixed here — coupons have
no soft-delete today, so closing this cleanly means either giving `Coupon`
one (a schema decision) or adding a constructor that carries only the id
that used to matter.
