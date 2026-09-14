# ADR-0020: The `OrderReturn` aggregate

Status: Accepted
Date: 2026-09-10 · Deciders: Stefan Marinkov

Implements [ADR-0019](0019-regulatory-compliance.md) §"The returns flow"
(Consumer Rights Directive 2011/83/EU, Arts. 9–15 — the 14-day right of
withdrawal). ADR-0019 fixed the *shape* of the flow; this ADR records the
aggregate-level decisions it left open — the model name, the relationship to
`orders.status`, cash-on-delivery, and where the 14-day guard reads its date.
ADR-0019 is not rewritten; it gains a one-line pointer to this file.

## Context

Before this change the only returns artefact in the codebase was the static
model withdrawal form at `/returns/withdrawal-form` (Annex I(B), shipped in
180a0c8). There was no way for a customer to actually *request* a return, no
14-day window enforced anywhere, no staff review surface, and no refund path.
`RefundPayment` and `RestockReturn` already existed; `OrderStatus::Returned`
already carried a whole-order restock side effect in `TransitionOrderStatus`.

## Decisions

### 1. The model is `OrderReturn`, table `returns`

`Return` is a PHP reserved word and cannot be a class name. `OrderReturn`
(table kept as `returns`) and `ReturnItem` (table `return_items`). ADR-0019's
prose says "`Return`" loosely; this is the concrete name.

### 2. The aggregate is fully independent of `orders.status`

`RequestReturn` / `ReviewReturn` / `RefundReturn` never call
`TransitionOrderStatus`. `RefundReturn` does its **own** per-`return_item`
`RestockReturn` and its own partial `RefundPayment`.

The alternative — driving `Delivered → Returned → Refunded` when a return
covers the whole order — was rejected because `OrderStatus::Returned` already
restocks *every* line of the order via `TransitionOrderStatus`'s inventory
effect. A line/quantity-granular return that also transitioned the order would
either double-restock the returned lines or throw
`InvalidArgumentException` from `RestockReturn` when the second restock found
`sold_quantity` already decremented. Keeping the two apart means each path
credits stock exactly once, and `RefundPayment`'s cap (checked inside the
`payments` lock) makes several partial refunds against one order accumulate
correctly.

**Accepted consequence:** an order every line of which has been returned still
reads `Delivered` in the customer's account list and in `OrderHistory`'s
"Completed" grouping. The return's own status — shown on the order-details
page and in the `ReturnResource` panel — is the source of truth for "was this
returned". `OrderStatus::Returned` / `Refunded` remain a separate whole-order
staff concept (e.g. cancelling and refunding an entire delivered order outside
the returns flow).

### 3. The 14-day window is an Action guard, sourced from the status history

`RequestReturn` refuses if the order is not `Delivered`, or if
`Order::deliveredAt()` + `config('returns.withdrawal_days')` (default 14) is
past. "Delivered when" is the `created_at` of the `order_status_histories` row
for the `Delivered` transition — `UNIQUE(order_id, new_status)` guarantees at
most one, and `OrderStatus`'s acyclic graph means it cannot be re-entered. No
`delivered_at` column. The figure is a config value so counsel can confirm it
against the Bulgarian implementation before go-live.

### 4. Cash on delivery: mark refunded, note it offline, still restock

A COD order has no Stripe charge to reverse (`RefundPayment` refuses one).
`RefundReturn` detects an order with no reversible card payment, skips the
Stripe call, appends an offline-cash note to `resolution_note`, sets the
return to `Refunded`, and **still runs `RestockReturn`**. Staff reconcile the
cash separately. Refusing COD returns entirely was rejected — the right of
withdrawal does not depend on the payment method, and the stock and the
refund record still need to be correct.

### 5. Refund covers goods value only

`sum(order_item.unit_price × return_item.quantity)`, gross (prices are stored
gross). **Delivery-cost reimbursement on a full withdrawal (CRD Art. 13) is
not handled** — a documented counsel gap in `regulatory-compliance.md` and
`write-rules/returns.md`, not silently ignored.

### 6. Permissions

`return` joins `NON_AUTHORED_RESOURCES` (a customer authors it, so no
`create_return`) → `viewAny_return`, `view_return`, `update_return`,
`delete_return`, plus a `refund` domain ability → `refund_return`.
`update_return` gates `ReviewReturn` (approve / deny); `refund_return` gates
`RefundReturn` and is administrator-only (ADR-0011, symmetric to
`refund_order` / `refund_payment`). `RefundReturn` passes the actor through to
`RefundPayment`, so the refunder also needs `refund_payment` — the returns
workflow and the actual disbursement are separate abilities. Both flow through
`PermissionCatalogue` → `PermissionSeeder` with no seeder edit; the
administrator holds all via `Gate::before`. Giving `warehouse_employee`
`update_return` would be a `RoleSeeder` decision, out of scope here.

## Consequences

- New migration `2026_09_09_130000_create_returns_tables.php` (`returns`,
  `return_items`). New enum `ReturnStatus`
  (`Requested → Approved/Denied`, `Approved → Refunded`, both terminal
  otherwise). New config `config/returns.php`.
- New Action area `App\Actions\Returns\`. New exceptions
  `ReturnNotAllowedException`, `IllegalReturnStatusTransitionException`. New
  policy `OrderReturnPolicy`. New `ReturnResource` (index + view only;
  Approve / Deny / Refund as header actions).
- New storefront route `/account/orders/{order}/return`
  (`Account\RequestReturn`), scoped through `auth()->user()->orders()` the
  same way `OrderDetails` is. The order-details page gains a "Request a
  return" button inside the window and shows each return's status.
- **GDPR:** `EraseCustomer` nulls a return's `reason` / `resolution_note`
  (customer free text) while keeping `status`, `refunded_amount` and the
  timestamps — that is the refund record. `ExportCustomerData` includes the
  customer's returns and their items. `PurgeAnonymisedOrders` takes
  `returns` / `return_items` with the order via the cascade FKs.
- `RaceWorker` gains a `request-return` action for
  `RequestReturnConcurrencyTest`.

## What stays a gap after this pass

- Delivery-cost reimbursement on a full withdrawal (decision 5).
- Automatic revalidation that a returned variation still exists (loaded
  `withTrashed()`; the inventory row outlives the variation, same as
  `TransitionOrderStatus`'s known gap 3).
- A staff role short of administrator that can review/refund returns
  (`RoleSeeder`).
- The abandoned-checkout / stuck-reservation issues and the cart-hold DoS
  surface raised alongside this work — tracked in `misc/todo.md`, not part of
  the returns aggregate.

## Alternatives rejected

**Drive `Delivered → Returned → Refunded` on a full return.** Double-restocks
the returned lines against `OrderStatus::Returned`'s existing whole-order
effect (decision 2).

**Refuse returns on COD orders.** The right of withdrawal is not conditional
on the payment method (decision 4).

**A `delivered_at` column on `orders`.** The status history already records
it, uniquely and unambiguously (decision 3).

**Name the model `Return`.** Fatal — PHP reserved word (decision 1).
