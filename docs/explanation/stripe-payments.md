# Stripe payments, end to end

How a card payment actually works in this application: what happens in what
order, which component owns which decision, and why the arrangement is the
shape it is. The decision to use Payment Intents rather than Checkout
Sessions is ADR-0016; this page describes the thing that decision produced.

For the security properties of the webhook specifically —
what the signature proves, what it does not, and what was verified by
removing each guard — see `security-model.md`, "The Stripe webhook". For how
to set any of this up locally, `how-to/set-up-stripe.md`.

## The shape of it

```
  Cart page                 CheckoutPage                 Stripe          Webhook
      │                          │                          │               │
      │  "Checkout" ────────────►│                          │               │
      │                          │                          │               │
      │              ┌───────────┴───────────┐              │               │
      │              │  ONE TRANSACTION      │              │               │
      │              │  CreateOrder          │              │               │
      │              │   ├ reserves stock    │              │               │
      │              │   ├ redeems coupon    │              │               │
      │              │   └ snapshots VAT     │              │               │
      │              │  RecordPayment        │              │               │
      │              │  CreateStripeIntent ──┼─────────────►│               │
      │              └───────────┬───────────┘   client_secret              │
      │                          │◄─────────────────────────│               │
      │                          │                          │               │
      │              Stripe Elements (card fields           │               │
      │              served by Stripe, never by us)         │               │
      │                          │  confirmPayment ────────►│               │
      │                          │                          │               │
      │                          │◄── redirect to           │               │
      │                     confirmation page               │               │
      │                     (NOT proof of payment)          │               │
      │                                                     │               │
      │                                     payment_intent.succeeded ──────►│
      │                                                     │               │
      │                                          TransitionPaymentStatus ───┘
      │                                                 payments.status = Paid
```

The important asymmetry: **the browser's redirect and the payment's status
are on different paths.** The customer can close the tab, lose their
connection, or never see the confirmation page, and the payment still
completes — because the only writer of `payments.status` is the webhook.

## Why the checkout write is one transaction

`CreateOrder` → `RecordPayment` → `CreateStripeIntent` run inside a single
`DB::transaction` in `CheckoutPage::placeOrder()`. A half-finished checkout
is the worst outcome available: an order holding reserved stock with no
payment row, or a payment row with no way to pay it.

The Stripe API call sits *inside* that transaction, which is normally worth
avoiding — a network call holding a database transaction open. It is
deliberate. An intent created against an order that then rolls back is a
charge the customer could complete for an order that does not exist.
Stripe's `idempotency_key` on the intent means a retry after a rollback
returns the same intent rather than creating a second one, so the cost is a
bounded number of orphaned intents, and the alternative — an order that
exists with no way to pay for it — is not bounded at all.

**The known cost:** stripe-php's default read timeout is 80 seconds
(`CurlClient::DEFAULT_TIMEOUT`), so a Stripe stall holds a `payments` row
lock for that long. Setting a shorter explicit timeout is the cheap
mitigation; queueing the webhook behind a fast 200 is the fuller one.
Neither is done — see "Known gaps" below.

## Where the money figure comes from

This is §37 criterion 8, and it is worth tracing the whole chain because
each link is a place a total could have been trusted and was not:

1. **The cart page** renders `CalculateCartTotals::forCart()` — a read, for
   display.
2. **`CheckoutPage`** renders the same thing again. It has *no price
   property at all*, so there is nothing for a browser to set. Livewire
   refuses to bind an undeclared property, which makes an injected `total`
   fail rather than be ignored.
3. **`CreateOrder`** recalculates the entire order from the cart's own rows
   — line prices, VAT per product, coupon discount — and writes
   `orders.total_amount`.
4. **`RecordPayment`** copies that figure off the order, never off a caller.
5. **`CreateStripeIntent`** reads it off the payment row and converts with
   `Money::toMinorUnits()`.

So the number sent to Stripe traces back to prices this application read
from its own database, through four hops, none of which accepts an amount
from outside.

**And then it is checked again on the way back.** When
`payment_intent.succeeded` arrives, `HandleStripeWebhookEvent` compares
`amount_received` (what Stripe actually captured — not `amount`, which is
what we asked for) and `currency` against the payment row before marking it
Paid. A partial capture or a currency mix-up records the event with a note
and moves nothing.

## Idempotency, in three separate places

They protect different things and are easy to conflate:

| Guard | Protects against | Mechanism |
|---|---|---|
| `idempotency_key` on intent creation | a retried checkout charging twice | Stripe returns the original intent |
| `payments.stripe_payment_intent_id` UNIQUE | two payments sharing an intent | schema constraint |
| `payment_events.stripe_event_id` UNIQUE | a redelivered webhook applying twice | insert-first, catch the violation |

The third is §37 criterion 11 and is the one with the subtlety.
`HandleStripeWebhookEvent` inserts the event row *before* changing status,
inside the same transaction, and catches `UniqueConstraintViolationException`
rather than checking `exists()` first. Two concurrent deliveries of the same
event would both pass a check; only one can win an insert.

Which half does what was established by removing each in turn against
`tests/Concurrency/StripeWebhookConcurrencyTest`:

- Removing the **UNIQUE index** is what would allow a double-apply. It is
  the actual guard.
- Removing the **catch** does not allow a double-apply — the index still
  refuses the second insert — but the losing delivery then returns 500 and
  Stripe retries a request that can never succeed. The catch makes a
  duplicate *graceful*, not *safe*.

## Refunds, and the cumulative-versus-delta trap

Stripe reports `charge.amount_refunded` as a **cumulative** running total.
`TransitionPaymentStatus::refundedTotal()` **accumulates** what it is given
onto `payments.refunded_amount`. Passing Stripe's figure straight through
therefore double-counts every refund after the first: refund 25, then 40,
and the row reads 65 instead of 40.

`HandleStripeWebhookEvent` computes the delta against what it already holds.
This also makes the echo harmless: refunding through the panel writes the
amount *and* causes Stripe to fire `charge.refunded` for the same money —
the delta comes out zero and the event records without moving anything.

A second subtlety in the same area: two successive partial refunds leave the
*status* unchanged (`PartiallyRefunded` → `PartiallyRefunded`) while the
*amount* moves. `PaymentStatus`'s matrix lists `PartiallyRefunded` as
reachable from itself precisely for this, and an early "nothing to do when
target equals current" return silently dropped every refund after the first
until a test caught it.

Full versus partial is decided by comparing amounts, never by trusting
`charge.refunded` — a real charge object carries that boolean as `false`
while partially refunded, so it would misclassify.

## What is deliberately not stored

`payment_events.payload` keeps only `id`, `status`, `amount`,
`amount_refunded`, `currency`, and a failure message. A real Stripe charge
object also carries `billing_details` (name, email, full address),
`payment_method_details.card.last4`, and a `receipt_url` that grants access
to a receipt page. None is stored: §33's data-minimisation applies to a
debugging column as much as anywhere, and this table is readable from the
admin panel.

Card data never reaches the application at all. Elements serves the card
fields from `js.stripe.com`, which is why `payments` has no card columns to
leak and why the PCI surface is SAQ-A rather than SAQ-D.

## Cash on delivery shares the road

COD is a sibling payment method, not a special case bolted on. It runs the
same `CreateOrder` → `RecordPayment` path and then stops: no intent, no
Elements, straight to the confirmation page. `CreateStripeIntent` refuses a
non-Stripe payment outright rather than silently doing nothing, so a COD
order reaching the Stripe path is a loud failure rather than a quiet one.

This is one of the reasons ADR-0016 chose Payment Intents: roughly half the
payment methods in scope never touch Stripe, and a Stripe-owned checkout
would have to be bypassed for them.

## Disputes

A customer can dispute a charge with their bank long after the money moved.
`charge.dispute.created` maps to `PaymentStatus::Disputed`, reachable only
from `Paid` and `PartiallyRefunded` — the states where money actually
arrived. A dispute against a `Pending` payment is recorded and refused by
the matrix, which is the right answer for an out-of-order delivery.

`Disputed` is deliberately **not terminal**: a dispute won by the merchant
returns to `Paid`, one lost ends at `Refunded`, since the funds are taken
back either way.

Three details worth knowing:

- **The amount guard does not apply.** Stripe documents a dispute's amount
  as *"usually the amount of the charge, but it can differ"*, so a partial
  dispute is legitimate. The guard that refuses a mismatched amount exists
  to stop marking something *Paid* for the wrong sum and is scoped to that.
- **`payment_intent` may be an expanded object.** Stripe types it
  `null|PaymentIntent|string`. Reading it as a string only returned null on
  an expanded payload and silently ignored the dispute — `intentIdFrom()`
  now accepts both shapes.
- **The amount guard is scoped by event type, not by target status —
  found while wiring the closing event below.** `charge.dispute.closed`
  with `status: won` also resolves to a `Paid` target, but a Stripe Dispute
  object carries no `amount_received` field at all — only `amount`, the
  disputed sum. Gating the guard on `$target === Paid` alone (its original
  form) meant `$received` was always `null` for a won dispute, always read
  as a mismatch, and the payment silently stayed `Disputed` regardless of
  the real outcome. The guard now also checks `$event->type ===
  'payment_intent.succeeded'`, the one event type that genuinely carries
  the field it reads. Caught by the regression test for the won-dispute
  path, not by inspection — the bug produced no error, just a payment that
  never moved.

**Closing events are now handled.** `charge.dispute.closed` resolves to
`PaymentStatus::Paid` when `status: won` and `PaymentStatus::Refunded` when
`status: lost`, mirroring `charge.refunded`'s pattern of a payload-dependent
target resolved in `statusFor()` rather than a flat `STATUS_BY_EVENT_TYPE`
entry — the event type alone does not say which way the dispute went.
`charge.dispute.closed` also fires for `warning_closed` (an inquiry that
never became a formal dispute) and other non-terminal statuses; only `won`
and `lost` are decisions this application acts on, and anything else is
acknowledged without a status change, the same treatment an unlisted event
type gets.

## Signing-secret rotation

Stripe recommends rolling the webhook signing secret periodically, and
during a roll it keeps the old secret valid for up to 24 hours, signing each
event with *every* active secret and putting one signature per secret in the
same header.

`VerifyStripeWebhookSignature` therefore accepts a list, not a single value:
`STRIPE_WEBHOOK_SECRET` plus an optional
`STRIPE_WEBHOOK_SECRET_PREVIOUS`. Set the latter to the outgoing secret for
the window, then remove it — leaving it set indefinitely widens the accepted
set for no benefit.

A one-secret implementation rejects events signed only with the *new*
secret, which silently drops real payments for a day. That is the specific
case `StripeWebhookSecurityTest` pins, and it was verified by reverting to a
single secret and watching only that test fail.

## IP allowlisting

Stripe's guidance is to use IP allowlisting **and** signature verification,
not either. The signature half is unconditional in the application; the IP
half belongs at the edge, where nginx can refuse a connection before PHP
starts.

`docker/nginx/stripe-ip-allowlist.conf.example` holds it, and it is
**deliberately not enabled**. `stripe listen` forwards events from your own
machine, so an allowlist would reject every locally forwarded event and look
exactly like a signature failure. The file documents how to enable it in
production, including the `real_ip_header` trap behind a load balancer,
where `$remote_addr` is the proxy and every request would be denied.

## Known gaps

Recorded rather than silently carried:

- **Synchronous webhook processing.** Stripe's own guidance is to queue.
  Fine at this volume; not fine at a subscription-renewal-shaped spike.
- **The 80-second SDK timeout** described above.
- **Dispute *closing* events are unhandled** — see Disputes.
- **Nothing has been run against the real Stripe API end to end.** Response
  shapes were verified against live test-mode objects; the request shapes
  this application sends have never been accepted by Stripe in anger.
  `reference/testing/stripe-testing.md` is explicit about what that does and does
  not leave open.
