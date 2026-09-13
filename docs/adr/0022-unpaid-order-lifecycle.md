# ADR-0022: The unpaid-order lifecycle

Status: Accepted
Date: 2026-09-11 · Deciders: Stefan Marinkov

How a card order that was never paid for stops holding stock, and when the
Consumer Rights Directive Art. 8(7) confirmation is actually sent.
Supersedes the "both payment paths email at placement" position recorded in
[ADR-0019](0019-regulatory-compliance.md)'s order-confirmation section and
described in `explanation/transactional-email.md`.

## Context

A confirmed, reproduced bug. Add to cart, check out, reach the Stripe
payment step, close the tab without entering a card. Driving the real
Actions the way `CheckoutPage::placeOrder` does, then stopping where the
customer stops:

```
BEFORE  reserved=0 current=60 available=60
AFTER   reserved=2 current=60 available=58
ORDER   status=new  payment=pending
```

Two separate defects, with one shared cause — nothing in this application
ever reacts to a card payment that simply never happens:

1. **`App\Mail\OrderPlaced` is queued anyway.** `placeOrder` queues it after
   the transaction commits, *before* the branch that separates cash on
   delivery from card. The customer is told "your order" for goods they
   never paid for.
2. **The reservation is never released.** `ReserveStock` holds the stock
   inside `CreateOrder`'s transaction; the four §20 release triggers all run
   through `TransitionOrderStatus`, and no caller ever reaches one for an
   abandoned order. The units are unsellable indefinitely.

### The state the order is actually in

`misc/todo.md` and the original bug report both assume an abandoned order
sits at `OrderStatus::AwaitingPayment`. **It does not.** `CreateOrder` lands
every order at `New` regardless of payment method, and `placeOrder` never
transitions it — `reference/write-rules/order.md` records this as
deliberate, because "a caller decides the first hop" and the Stripe and COD
paths diverge there.

So an abandoned card order is at `new`, indistinguishable from a freshly
placed COD order. The only rows in `awaiting_payment` on a seeded database
are `DemoOrderSeeder`'s, which walk `New → AwaitingPayment → Paid`
deliberately.

This matters more than it looks. A sweep written against `AwaitingPayment` —
the obvious shape, and the one the todo assumed — would cancel seeded demo
orders while matching **no** genuinely abandoned order, and its test would
pass green. That is exactly the "green static check, wrong behaviour" class
`coding-conventions.md`'s testing-discipline section exists to prevent.

### What Stripe can and cannot tell us

[ADR-0016](0016-payment-intents-over-checkout-sessions.md) chose Payment
Intents with Elements over Checkout Sessions. There is therefore **no
`checkout.session.expired` event for this integration** — that half of the
question the todo raised is foreclosed by an accepted decision, not open.

What remains is `payment_intent.canceled`. It is already mapped in
`HandleStripeWebhookEvent::STATUS_BY_EVENT_TYPE` to
`PaymentStatus::Cancelled`, but it moves only `payments.status`:
`TransitionPaymentStatus` deliberately does not touch `orders.status`, so
nothing releases stock today even when that event does arrive.

It also does not arrive promptly. Stripe cancels an abandoned PaymentIntent
on its own schedule, and a customer who closes a tab generates no event at
all at the moment they leave. An event that may arrive in days, or never,
cannot be the mechanism that frees contested stock.

## Decisions

### 1. A Stripe order moves to `AwaitingPayment` when its intent is created

`placeOrder` calls `TransitionOrderStatus($order, AwaitingPayment, null)`
for the card path only, inside the checkout transaction, immediately after
`CreateStripeIntent` succeeds. COD keeps its existing first hop — `New`,
advancing to `Confirmed` when staff confirm it.

Both edges already exist in `OrderStatus::allowedTransitions()`
(`New => AwaitingPayment`, `AwaitingPayment => Cancelled`). No enum change,
no migration.

This is the load-bearing decision, and it is worth stating why it comes
first: it is what makes "an unpaid card order" a state the database can be
*queried* for. Without it, any sweep is guessing at `New` rows and cannot
distinguish an abandoned card checkout from a COD order placed thirty
seconds ago that is waiting for staff.

`UNIQUE(order_id, new_status)` on `order_status_histories` permits exactly
one visit to `AwaitingPayment` per order, which is correct — `OrderStatus`'s
graph is acyclic, and a customer retrying the card form on the same order
re-enters a status it already holds, which `TransitionOrderStatus` treats as
a clean no-op rather than a second history row.

### 2. A scheduled sweep is the mechanism; the webhook is a fast path

**`App\Actions\Order\ExpireUnpaidOrders`**, run by
`orders:expire-unpaid` on the scheduler, cancels every `AwaitingPayment`
order whose `AwaitingPayment` history row is older than
`config('orders.unpaid_ttl_minutes')`. It cancels through
`TransitionOrderStatus($order, Cancelled, null)` and therefore releases
stock via ADR-0011's structural inventory effect — this Action composes no
inventory call of its own and must not.

The sweep is the primary mechanism because it is the only one that does not
depend on an external system electing to tell us something. It is driven by
our own clock, over our own rows, and it fires for the overwhelmingly common
case: a customer who closed a tab and generated no event whatsoever.

`payment_intent.canceled` and `payment_intent.payment_failed` additionally
propagate to the order, cancelling it immediately rather than waiting out
the TTL (decision 4). That is a latency improvement on the sweep, not a
substitute for it.

### 3. The TTL is 10 minutes, from config

`config('orders.unpaid_ttl_minutes')`, `ORDERS_UNPAID_TTL_MINUTES`, default
10. The scheduler runs the command `everyMinute()`, so the observed release
lands within roughly a minute of the deadline.

Ten minutes rather than a longer window so that **one timer governs both
this and the checkout-state timeout** `misc/todo.md` raises separately ("a
checkout sitting unfinished for 10 minutes should revert to cart state").
Two different deadlines for "this checkout is over" would be two facts to
keep in sync, and the stock hold is the half that actually costs something.

**The accepted risk, stated plainly:** ten minutes is shorter than the
slowest legitimate card payments. A 3-D Secure challenge that bounces
through a bank app, on a phone, with the customer hunting for a card reader,
can exceed it. When that happens the order is cancelled and the stock
released while the customer is still paying, and a late
`payment_intent.succeeded` then arrives for a `Cancelled` order.

That case is handled rather than ignored: `PaymentStatus`'s matrix still
moves the payment to `Paid` (the money genuinely arrived), while
`OrderStatus::Cancelled => Paid` is not a legal edge, so the order stays
cancelled and the mismatch is visible in the panel as a paid payment against
a cancelled order — a refund-and-apologise case for staff, not a silent
wrong state. Raising the TTL is a one-line config change if that turns out
to happen in practice; the figure is in config precisely so it does not need
a code change to revisit.

### 4. The webhook propagates a dead intent to the order

`HandleStripeWebhookEvent` gains an order-side effect for exactly two event
types — `payment_intent.canceled` and `payment_intent.payment_failed` —
cancelling the order through `TransitionOrderStatus` when it is still
`AwaitingPayment`.

Deliberately narrow:

- **Only from `AwaitingPayment`.** A failed payment against an order staff
  have already confirmed is not the webhook's business to cancel.
  `payment_intent.payment_failed` is also *retryable* — `PaymentStatus`'s
  matrix has `Failed => Paid` for exactly that — so cancelling from any
  other state would destroy an order a customer is about to pay for on a
  second attempt.
- **Never marks an order `Paid`.** `TransitionPaymentStatus`'s docblock
  states the reasoning and it still holds: whether a paid payment advances
  its order is a separate decision with its own policy. This ADR does not
  change that; it adds only the *negative* propagation, where there is no
  authorization question because cancelling an unpaid order takes nothing
  from anybody.
- **Null actor.** Stripe is the actor, and a null actor skips the policy
  check per ADR-0007 — the same path the sweep uses, and the reason neither
  needs a `cancel_order` permission holder to exist.

### 5. The card path's confirmation email moves to payment

`OrderPlaced` is sent:

- **cash on delivery** — at placement, from `placeOrder`, unchanged;
- **card** — when the payment reaches `PaymentStatus::Paid`, never at
  placement.

This supersedes the position in `OrderPlaced`'s own docblock and in
`explanation/transactional-email.md` that the contract is concluded at
placement for both paths and the email may therefore state "payment
pending". For a card order that reading produces the exact defect this ADR
exists to fix: a durable-medium confirmation of a concluded contract, sent
for a contract that was never concluded because the customer never paid.
CRD Art. 8(7) requires the confirmation *of the concluded contract*; for a
card sale the conclusion is the payment.

**Where it is sent from: a listener on `OrderStatusChanged`, not the
webhook Action.** ADR-0011 already decided this shape — an effect that
cannot be rolled back (a queued email) belongs on the event after commit,
not inside the transaction. `App\Events\OrderStatusChanged` was built for
this and has carried no listeners until now; its docblock says so
explicitly ("No listeners yet — §28's queued emails are a later slice").

This requires the order to reach `OrderStatus::Paid`, which means the
webhook must advance the order and not only the payment. Decision 4 declines
to do that for the positive case, so the advance is instead driven from the
same place as the negative one and gated identically: only from
`AwaitingPayment`, only on `payment_intent.succeeded`, null actor.

## Consequences

- New `config/orders.php`. New `App\Actions\Order\ExpireUnpaidOrders`, new
  `orders:expire-unpaid` command, a new `everyMinute()` schedule entry.
- New `app/Listeners/` directory — the first in this codebase. Laravel
  auto-discovers listeners there; since nothing has ever relied on that,
  the discovery is proven by a test that asserts the mail is queued through
  a real transition, not by trusting the framework default.
- `CheckoutPage::placeOrder` no longer queues `OrderPlaced` unconditionally.
  The COD branch queues it; the card branch does not.
- `HandleStripeWebhookEvent` gains an order-side effect and therefore a
  dependency on `TransitionOrderStatus`. It now locks `orders` as well as
  `payments`.
- **Lock order: `payments` before `orders`.** This is a *new* declared
  order and it is the opposite of `TransitionOrderStatus`'s own
  `orders`-then-`inventories`. No cycle: nothing takes an `orders` lock and
  then reaches for `payments`. `reference/write-rules/concurrency.md`
  records it, because a future Action that locked in the other direction
  would deadlock against the webhook rather than merely wait.
- The sweep and a late webhook can race for the same order. Both go through
  `TransitionOrderStatus`, so the `orders` row lock serialises them and the
  loser finds its target no longer legal from the winner's landing status —
  the existing, tested failure mode, not a new one.
- A demo database's seeded `AwaitingPayment` orders are now sweepable. They
  are seeded with backdated history, so `demo:seed` followed by a scheduler
  tick will cancel them. Acceptable: the scheduler does not run locally
  (`misc/todo.md` records that no compose service runs `schedule:run`), and
  a demo that wants them preserved should not be running the sweep.
- `explanation/transactional-email.md`'s claim that an abandoned card order
  "is cancelled by `carts:expire` / the payment-failure path" was false when
  written and is made true by this ADR.

## Alternatives rejected

**`checkout.session.expired`.** Does not exist for this integration —
ADR-0016 chose Payment Intents. Would require abandoning that decision
wholesale to gain one event.

**`payment_intent.canceled` as the only mechanism.** Rejected as primary:
Stripe cancels an abandoned intent on its own schedule and emits nothing at
the moment the customer leaves. Stock would be held for as long as Stripe
felt like it, and for a customer who closes a tab on an intent Stripe never
cancels, forever. Kept as the fast path (decision 4) because when the event
*does* arrive it is better information than a timer.

**Releasing stock without cancelling the order.** Calling `ReleaseStock`
directly from the sweep and leaving the order at `AwaitingPayment`. Rejected
for the reason ADR-0011 gives for having no `CancelOrder` wrapper: the
inventory effect belongs to the status transition, and an order whose stock
has been released but which still reads "awaiting payment" is a state no
other part of the system knows how to interpret. It would also be
indefinitely re-swept, since nothing would change to take it out of the
query.

**A `reserved_until` / `awaiting_payment_at` column on `orders`.** Rejected:
`order_status_histories` already records exactly when the order entered
`AwaitingPayment`, `UNIQUE(order_id, new_status)` guarantees that row is
unique, and ADR-0020 set the precedent of reading a transition's timestamp
from the history rather than denormalising it (`Order::deliveredAt()`).
A column would be a second copy of a fact the history already holds.

**Sending `OrderPlaced` at placement for a card order and a second
"payment received" email later.** Rejected as worse than the bug: two emails
for one purchase, the first of which is the misleading one this ADR exists
to remove.

**Having the sweep rebuild the customer's basket too.** Raised once
`RestoreCartFromOrder` existed for the Cancel button (which does exactly
that). Declined: the scheduler runs with no session, and a guest's cart is
keyed on `session_id`, so the sweep could only ever restore for a
*signed-in* customer — a behaviour that silently does nothing for the
guests who are most of the abandoned checkouts. The sweep's job stays
single: release the stock. A customer who returns starts a fresh basket.
(Housekeeping note added 2026-09-11 while implementing items 2–4; it
records a question this ADR did not address rather than revising anything
it decided.)

**Cancelling the order but emailing the customer about it.** Considered and
deliberately deferred — there is no legal obligation
(`explanation/transactional-email.md` already lists it as an optional
follow-up), and a customer who abandoned a checkout has not been told
anything untrue by silence. Worth revisiting if support traffic suggests
otherwise.
