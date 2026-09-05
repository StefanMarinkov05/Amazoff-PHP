# Coupon writes — expected behaviour

What happens for every write against a coupon's application, removal, and
redemption, alone, after the coupon changes underneath a cart, and under
concurrency. Facts as of 2026-08-17, measured against the running stack
rather than read off the code.

Why the mechanisms differ is `explanation/concurrency-and-locking.md`. This
page is the outcomes, in the same shape as its siblings,
`reference/write-rules/cart.md` and `reference/write-rules/product.md`.
`misc/actions-plan.md`'s coupon section is the design record this slice was
built from; ten decisions, cited by number below where an outcome traces
back to one. (`misc/` is gitignored, so that record is local to whoever has
it — the outcomes below are the committed form.)

## What enforces any of this

The Actions in `app/Actions/Coupon`, and nothing else. Direct Eloquent, a
factory, a seeder, or a raw query builder all bypass every rule below.

**[Changed]** `CartPage` calls `ApplyCoupon` and `RemoveCoupon` —
`CartPageTest` (`reference/ui-tests.md`) is what proves the wiring, on top
of this page's own outcomes. `RedeemCoupon` is no longer callerless either:
`CreateOrder` calls it directly at checkout. All three are still exercised
directly by their own Action tests as well.

`CouponResource` stays default Filament CRUD (decision 10) — creating or
editing a `Coupon` row never touches `coupon_redemptions`, so none of this
page's Actions run when staff manage coupons in the admin panel.

Editing a coupon's `products`/`productCategories` eligibility list is a
Filament relationship-select, not an Action — it saves as a `detach()` of
removed rows followed by a `sync()` of added ones, two separate
statements. `CalculateCouponDiscount::forLines()` (called by both
`ApplyCoupon` and `RedeemCoupon`) reads `coupon_product`/
`coupon_product_category` live, uncached, so a checkout landing between
those two statements would see a transiently smaller eligible-product
list than either the old or new intended state. Closed by
`AdminPanelProvider::panel()` calling `->databaseTransactions()`, which
wraps a Filament page's whole record-save-plus-relationship-sync in one
`DB::transaction()` — not specific to `CouponResource`, and also closes
the same exposure on `ProductForm`'s `attributes` select and `RoleForm`'s
permission checklists.

## One actor at a time

| Action | Refused when | Exception |
|---|---|---|
| `ApplyCoupon` | coupon code does not exist | `ModelNotFoundException` (not a domain exception — `firstOrFail()`) |
| | coupon `is_active = false` | `CouponNotApplicableException::inactive()` |
| | outside `starts_at`/`ends_at` | `CouponNotApplicableException::outsideWindow()` |
| | cart subtotal below `minimum_order_value` | `CouponNotApplicableException::belowMinimum()` |
| | `products`/`categories`-scoped coupon matches no line in the cart | `CouponNotApplicableException::outOfScope()` |
| | logged-in customer already at `usage_limit_per_customer` | `CouponNotApplicableException::perCustomerLimitReached()` |
| `RemoveCoupon` | — never refuses | — |
| `RedeemCoupon` | any of `ApplyCoupon`'s six checks, re-evaluated | same `CouponNotApplicableException` constructors |
| | `total_usage_limit` already reached | `CouponNotApplicableException::totalLimitReached()` |
| | `usage_limit_per_customer` already reached (by `email_hash`, not `user_id` — guests included) | `CouponNotApplicableException::perCustomerLimitReached()` |

`ApplyCoupon` does not check the per-customer cap for a guest cart at all —
not "cannot fail it," genuinely skipped. `carts` carries no email until
checkout collects one, so there is nothing to hash and count against
(decision 6). This is a **deliberate deviation** from §10–12, which lists
discount codes among cart-level actions before the customer-information
step; recorded here per CLAUDE.md's instruction to say so rather than
silently diverge. Logged-in customers are unaffected — `user_id` is
available immediately, so their cap is checked at apply time.

`RedeemCoupon` re-checks everything independently, including the six
`ApplyCoupon` already validated — nothing from the cart's provisional state
is trusted. It is also the only Action here that takes a lock; see "Lock
order" below.

Neither `ApplyCoupon` nor `RemoveCoupon` authorizes anything — a customer
applying a coupon to their own cart holds no permission to check, same
reasoning as `AddToCart`/`RemoveFromCart`. `RedeemCoupon` takes no `?User
$actor` either: it runs inside an already-authorized checkout request, and
`coupon_redemptions.user_id`/`email_hash` both derive from the `Order`, not
from a caller (decision 4, ADR-0007's stated exception for Actions with no
non-human caller).

## A coupon after being applied

`ApplyCoupon` and `RemoveCoupon` write only `carts.coupon_id`. No discount
amount is ever stored on the cart — decision 7's "current state, never
stored" pattern, the same one `ResolveVariationPrice` uses for price. There
is nothing on a cart line for a coupon edit to invalidate, which is what
makes every row below a re-read rather than a write.

| Change | `carts.coupon_id` | The next render (`CalculateCouponDiscount`) | Evidence |
|---|---|---|---|
| Admin disables the coupon | left alone | computes no discount, `CouponNotApplicableException::inactive()` | `ApplyCouponTest` |
| Admin raises `minimum_order_value` above the cart's subtotal | left alone | refused, `belowMinimum()` | `ApplyCouponTest` |
| Admin narrows the window to exclude now | left alone | refused, `outsideWindow()` | inferred from the same mechanism as the two rows above; not separately pinned |
| Customer applies a second, different coupon | overwritten — last apply wins, silently | prices against the new coupon | `ApplyCouponTest`, "overwrites a previously applied coupon silently" |

`RemoveCoupon` is the only way to clear the first three rows; nothing
purges `carts.coupon_id` automatically, and nothing scans carts when a
coupon is edited. `RedeemCoupon` re-validates everything regardless, so a
stale `coupon_id` reaching checkout is refused there even if the customer
never triggers a re-render in between — "apply succeeds, admin disables the
coupon, redemption still refuses" is `RedeemCouponTest`'s own test
obligation from the plan.

## Two actors at once

Unlike Cart's races, both of which let both attempts succeed, coupon
redemption is the opposite shape: **exactly one wins, and there is no
constraint making that certain.**

| Race | Outcome | Evidence |
|---|---|---|
| Two different customers redeeming a coupon at `total_usage_limit = 1` | exactly one `CouponRedemption` row; the loser gets a clean `CouponNotApplicableException::totalLimitReached()` | `RedeemCouponConcurrencyTest`, "lets exactly one of two different customers redeem" |
| 2 orders from the same customer (same `email_hash`, e.g. two tabs, a double-submitted "place order") redeeming at `usage_limit_per_customer = 1` | exactly one `CouponRedemption` row; the loser gets `perCustomerLimitReached()` | `RedeemCouponConcurrencyTest`, "lets exactly one of 2 orders from the same customer" |
| The same order redeeming the same coupon twice (retry, double-submit) | one row; the second call returns the first call's row rather than inserting or throwing | `RedeemCouponTest`, "returns the existing row rather than inserting twice" |

The first two are **not** backstopped by a `CHECK` constraint — no
constraint can span `coupons` and `coupon_redemptions`, and
`2026_08_11_094725_add_check_constraints_to_domain_tables.php`'s own
trailing comment says so explicitly. Without `lockForUpdate()` on the
`coupons` row, both processes read the pre-redemption count, both pass
their own check, and both insert — measured directly: temporarily removing
the lock turns both concurrency tests into two-winner failures (verified
while building this slice, not asserted from reading the code). The
assertion these tests make is the winner **count**, the
`PublishProductConcurrencyTest` shape from `product.md`, not the
`ReserveStockConcurrencyTest` shape — there is no floor under an unlocked
race here the way `chk_inventories_reserved_not_above_current` provides one
for stock.

The third race is a different mechanism entirely: `UNIQUE(coupon_id,
order_id)` is a real constraint, so the second `RedeemCoupon` call for the
same order catches `UniqueConstraintViolationException` and returns the
existing row rather than raising — CLAUDE.md's idempotency rule, not
ADR-0008's locking one. Single-process is sufficient to prove this one; no
window between two connections is being demonstrated, only that the retry
path does not insert twice.

**`ApplyCoupon` under concurrency is deliberately not tested.** Two tabs
applying (possibly different) coupons to the same cart race a blind
`UPDATE` on a nullable FK with no invariant spanning the two attempts —
decision 7 already makes last-write-wins the intended outcome. Same shape
as `SetMainProductImage`: nothing is read to decide anything a second
writer could invalidate, so there is no window and no mechanism to remove.

## Lock order

`RedeemCoupon` takes `lockForUpdate()` on the `coupons` row, before either
usage-cap count. Declared order per decision 5: **`products`, then
`coupons`, then `inventories`.** Coupon redemption locks exactly one row and
is independent of which products are in the cart, so it sits between the
(possibly multiple, PK-sorted) `products`/`inventories` locks rather than
racing either.

`CreateOrder` is exactly that composition, built and exercised — it locks
`coupons` (via `RedeemCoupon`) before `inventories` (via `ReserveStock`),
matching the declared order above. `write-rules/order.md`'s "Lock order"
section is the current, authoritative account of that path; this page's
description of the rule stands independently of which Action first needed
it.

`ApplyCoupon` and `RemoveCoupon` take no lock at all — nothing they do is
contested state; see "Two actors at once" above for why `ApplyCoupon`'s
blind overwrite needs none.

## Known gaps

**1. Mixed-VAT-rate apportionment is notional, not stored.** A
`categories`-scoped coupon spanning lines at different `vat_rate`s has its
discount apportioned per line, proportional to each line's share of the
matched subtotal, purely inside `CalculateCouponDiscount::vatAfterDiscount()`
— never written back onto an `order_items` row. This satisfies decision 3's
letter ("one number stored once on the order, not allocated back per
line") but is an extension of it the plan itself left open ("Open, not
settled here"), not something decision 3 states outright.

**[Changed]** Until 2026-09-03, `vatAfterDiscount()` went further than this
gap describes: for a `products`/`categories`-scoped coupon, it summed VAT
over the *matched* lines only, dropping an unmatched line's VAT from the
total entirely rather than keeping it at its untouched value — a real
defect, not the apportionment-is-notional characterization above, which
concerns how a *matched* line's own share is computed and remains accurate.
`CreateOrder` assigns this return value straight onto `orders.vat_amount`,
so every order redeeming a scoped coupon against a partially-matched cart
understated its recorded VAT until the fix. Now covers every line handed to
`forLines()`: a matched line's share of the discount is subtracted before
VAT extraction as before; an unmatched line's VAT is extracted from its
untouched total. `CalculateCouponDiscountTest`'s two scoped-coupon cases
gained `vat` assertions — previously they checked `discount` only, which is
how this went unnoticed. `CartPageTest`'s scoped-coupon case exercises the
same fix at the component layer.

**2. Same-order double redemption is proven single-process only.** The
`UNIQUE(coupon_id, order_id)` retry path (`RedeemCouponTest`, "returns the
existing row") is not raced across two real connections — per the plan's
own test-obligations table, no window between two connections needs
proving for this case, only that the retry path does not insert twice.
