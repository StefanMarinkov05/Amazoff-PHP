# Return writes — expected behaviour

What happens when a customer withdraws from a delivered order, alone and under
concurrency. The `OrderReturn` aggregate (ADR-0020, implementing ADR-0019
§"The returns flow"; Consumer Rights Directive 2011/83/EU, Arts. 9–15).

Same shape as its siblings, `order.md` and `coupon.md`. The status graph is
`ReturnStatus::allowedTransitions()`:
`Requested → {Approved, Denied}`, `Approved → {Refunded}`, `Denied` and
`Refunded` terminal — and it runs **beside** `orders.status`, never inside it
(see "Relationship to the order status" below).

## What enforces any of this

`App\Actions\Returns\RequestReturn`, `ReviewReturn`, `RefundReturn`, and
nothing else. Direct Eloquent, a factory or a seeder bypass every rule below.

## RequestReturn — the customer asks

Authorizes nothing: the customer owns the act, and ownership is the order
being theirs (the storefront scopes `auth()->user()->orders()`), not a
permission — the `CreateProductReview` precedent.

| Refused when | Exception |
|---|---|
| The order has not reached `Delivered` | `ReturnNotAllowedException::orderNotDelivered` |
| More than `config('returns.withdrawal_days')` (default 14) days have passed since the `Delivered` `order_status_histories` row's `created_at` | `::windowExpired` |
| Nothing is selected — no line, or every quantity zero | `::nothingSelected` |
| A selected `order_item_id` is not on this order | `::lineNotOnOrder` |
| Requested quantity > ordered quantity − quantity already held by this order's returns that are not `Denied` | `::quantityExceedsRemaining` |

A refusal writes **nothing** — no `returns` row, no `return_items`. The whole
method is one `DB::transaction` with `lockForUpdate()` on `orders`.

**What gets written:** one `returns` row (`status = Requested`, `requested_at`
= now, `reason` purified via ADR-0015's HTML sanitiser) and one `return_items`
row per selected line. No stock or payment effect — the item has not
physically come back yet.

## ReviewReturn — staff approve or deny

Authorizes `update` (`update_return`). Locks `returns`, re-reads status from
the locked row (the `TransitionOrderStatus` pattern).

| Refused when | Exception |
|---|---|
| The move is not in `ReturnStatus::allowedTransitions()` | `IllegalReturnStatusTransitionException` |
| The actor lacks `update_return` | `AuthorizationException` |

`$from === $to` is a clean no-op, checked *after* authorization (a denied
actor still gets `AuthorizationException`). Writes `status`,
`resolution_note`, `resolved_at`.

## RefundReturn — money back, stock back

Authorizes `refund` (`refund_return`, administrator). Passes the actor through
to `RefundPayment`, which authorizes `refund_payment` — the refunder needs
both. Locks `returns`, then (via the composed Actions) `payments`, then
`inventories`.

| Refused when | Exception |
|---|---|
| The return is not `Approved` | `ReturnNotAllowedException::notApproved` |
| The actor lacks `refund_return` (or `refund_payment` on the card path) | `AuthorizationException` |

**What it does, in order:**

1. Computes the goods total: `Σ order_item.unit_price × return_item.quantity`,
   gross, via `App\Support\Money`.
2. If the order has a `PaymentMethod::Stripe` payment in
   `Paid`/`PartiallyRefunded` with an intent → `RefundPayment->handle(...)`
   for that amount (its own lock, its own cap — a second return's refund
   accumulates against `refunded_amount` and cannot overshoot).
   Otherwise (COD, or no payment row) → **no Stripe call**; an offline-cash
   note is appended to `resolution_note`.
3. `RestockReturn->handle(...)` per `return_item` — `sold → current`, counted
   as a return — variations loaded `withTrashed()`.
4. Sets `status = Refunded`, `refunded_amount`, `resolved_at`.

**What it does not do:** touch `orders.status`. Delivery-cost reimbursement on
a full withdrawal (CRD Art. 13) is **not** computed — a documented counsel
gap.

## Relationship to the order status

The aggregate is **independent of `orders.status`** (ADR-0020, decision 2).
`OrderStatus::Returned` already restocks *every* line of the whole order via
`TransitionOrderStatus`; a granular return that also transitioned the order
would double-restock the returned lines or throw from the second
`RestockReturn`. So:

- A fully-returned order still reads `Delivered` in the account list. The
  return's own status is the truth for "was this returned", shown on the
  order-details page and in `ReturnResource`.
- `OrderStatus::Returned` / `Refunded` stay a whole-order staff move made
  outside this flow.

Diagram: [return-status-states.puml](../diagrams/return-status-states/return-status-states.puml).

## Two actors at once

| Race | Outcome | Evidence |
|---|---|---|
| Two `RequestReturn` for the same line, together exceeding what is left | exactly 1 writes a `returns` row; the loser re-reads under the `orders` lock, finds the line's remaining quantity already spoken for, and gets a clean `ReturnNotAllowedException` — never a `QueryException`, and the line is never over-returned | `RequestReturnConcurrencyTest` |
| Two `RefundReturn` on returns against one order, each fitting alone but together exceeding the payment | `RefundPayment`'s cap, checked inside the `payments` lock, refuses the loser with `RefundNotAllowedException`; the whole `RefundReturn` transaction rolls back, so the loser's stock is not restocked and its status is unchanged | covered transitively by `RecordPaymentConcurrencyTest`'s refund-cap tests + `RefundReturnTest`'s accumulation case |

## Lock order

`orders` (in `RequestReturn`) — standalone. `returns` → `payments` (via
`RefundPayment`) → `inventories` (via `RestockReturn`, per line sorted by
`product_variation_id`) in `RefundReturn`. `concurrency.md` has the declared
global order.

## GDPR interaction (ADR-0019 / ADR-0020)

- **Erasure** (`EraseCustomer`): a return's `reason` and `resolution_note`
  are customer free text and are overwritten (`reason` → `[erased]`,
  `resolution_note` → `null`) alongside `orders.customer_note`. `status`,
  `refunded_amount`, `requested_at`, `resolved_at` are **kept** — that is part
  of the retained financial record.
- **Export** (`ExportCustomerData`): the customer's returns and their items
  (product name + quantity) are included.
- **Purge** (`PurgeAnonymisedOrders`): `returns` / `return_items` cascade with
  the order via the FKs.

## Known gaps

1. **Delivery-cost reimbursement on a full withdrawal is not computed** (CRD
   Art. 13). The refund is goods value only. Flagged for counsel.
2. **A return does not revalidate that its variations still exist** beyond
   loading them `withTrashed()`. The `inventories` row outlives the variation
   (same as `order.md` known gap 3), so the counters still move correctly —
   recorded because it is easy to assume otherwise.
3. **No staff role short of administrator** can review or refund a return.
   `warehouse_employee` holding `update_return` would be a `RoleSeeder`
   change.
