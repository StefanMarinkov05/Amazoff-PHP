# ADR-0016: Payment Intents with Elements, not Checkout Sessions

Status: Accepted
Date: 2026-09-01 · Deciders: Stefan Marinkov

## Context

§37 criteria 9–11 require Stripe test payments, webhook-driven status
updates, and duplicate-event protection. ADR-0001 already settled that Stripe
is used directly rather than behind an interface — one implementation,
nothing to swap it for. What it did not settle is *which* Stripe integration
shape, because at the time nothing was built.

Stripe offers two routes to the same outcome:

- **Checkout Sessions + Payment Element** — Stripe owns more of the flow.
  Fewer lines of application code, and some features (notably Adaptive
  Pricing) exist only here.
- **Payment Intents + Elements** — the application creates the intent,
  computes the amount, and orchestrates its own checkout state.

Stripe's current documentation states a default preference, repeatedly and
verbatim:

> "Stripe recommends using the Checkout Sessions API with the Payment Element
> over Payment Intents for most integrations… Don't use the Payment Intent
> API unless the user explicitly asks, because it requires significantly more
> code."

This project built the Payment Intents route. That is a deliberate
divergence from a documented vendor default, which is exactly the kind of
decision CLAUDE.md says must be recorded rather than left as drift — a
future reader comparing this code against Stripe's docs would otherwise
reasonably conclude it was written in ignorance of the recommendation.

Stripe's own `stripe_implementation_planner` tool was run against this
project's business context. It walked `no_stripe_only → in_browser →
no_digital_managed → no_invoice → no_payment_links → own_checkout_state` and
terminated at "Elements with the Payment Intents API" — the shape already
built. The `own_checkout_state` branch exists precisely for merchants who
must own their totals and orchestration.

## Decision

**Payment Intents with Elements, not Checkout Sessions.**

The deciding factor is that this application cannot hand its checkout state
to Stripe, because several graded requirements live *between* "customer
clicks pay" and "money moves":

- **Server-recalculated totals (§37 #8).** `CreateOrder` reprices the whole
  order from the cart's own rows. The figure sent to Stripe is derived from
  that, never from the browser.
- **Coupon usage caps.** `RedeemCoupon` enforces per-coupon and
  per-customer limits under a `coupons` row lock (ADR-0008).
- **Stock reservation.** `ReserveStock` holds inventory at order time, under
  a lock, before payment is confirmed — so two customers cannot both reach
  the card step for the last unit.
- **Per-product VAT snapshots.** Prices are stored gross (BG B2C), and
  `vat_rate` is snapshotted onto each order item at checkout.
- **Cash on delivery as a sibling method.** COD orders skip Stripe entirely
  and are marked paid on courier remittance. A Stripe-owned checkout would
  have to be bypassed for roughly half the payment methods in scope.

All five happen inside one transaction in `CheckoutPage::placeOrder()`
before an intent exists. A Checkout Session would either duplicate that
orchestration or move it after the payment, and "reserve the stock after
taking the money" is the wrong order for an inventory-backed shop.

### What this costs

**Adaptive Pricing** is the main Checkout-Sessions-only feature forgone. The
catalogue is single-market EUR (`Money` is deliberately not currency-aware —
see its docblock and `schema/open-schema-questions.md` #2), so it does not
apply today. If multi-currency ever lands, this ADR is the first thing to
re-read.

**More code to own, and therefore more to get wrong.** Stripe's warning is
about volume of code, and it is fair. The mitigation is that the code is
narrow and heavily tested: `CreateStripeIntent`,
`HandleStripeWebhookEvent`, and `RefundPayment` are three Actions,
`tests/Feature/Payment/` holds 58 tests including an adversarial suite
against the webhook, and `tests/Concurrency/StripeWebhookConcurrencyTest`
races the paths a single process cannot.

**The webhook is ours to secure.** A hosted Checkout Session still needs a
webhook, so this is less of a delta than it looks — but the endpoint being
CSRF-exempt and signature-verified is entirely this application's
responsibility. `explanation/security-model.md`, "The Stripe webhook", is
the record of how, and what was proven by removing each guard in turn.

## Consequences

- The API version is pinned in `AppServiceProvider` to the installed SDK's
  `ApiVersion::CURRENT`. `HandleStripeWebhookEvent` reads payload fields
  positionally (`amount_received`, `amount_refunded`,
  `last_payment_error->message`), and a webhook endpoint's version is fixed
  at creation in the Stripe dashboard — so the SDK and the endpoint must be
  moved together, deliberately, not drift apart.
- Stripe's go-live checklist applies to this project in full, and the items
  not yet done are recorded as gaps rather than silently skipped: IP
  allowlisting at the edge, signing-secret rotation support, and dispute
  (`charge.dispute.created`) handling.
- If Stripe's own recommendation is ever revisited — say the team decides the
  orchestration is not worth owning — that is a new ADR superseding this
  one, not an edit here.

## Alternatives rejected

**Checkout Sessions + Payment Element.** Rejected for the orchestration
reasons above, not on preference. Reconsider if COD leaves scope and totals
stop being computed locally.

**Stripe Payment Links.** No server-side control at all; incompatible with
every bullet in the Decision section.

**An interface over Stripe so either could be swapped in later.** Refused by
ADR-0001 by name. An interface over one implementation is speculative
generality; the container binding in `AppServiceProvider` already provides
the only thing that was actually needed, which is a test seam.
