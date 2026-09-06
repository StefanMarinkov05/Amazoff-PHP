# Stripe — what is tested, and what is not

Facts about the payment test suite: which file proves which property, what
was verified by breaking it, what was checked against the real Stripe API,
and — the part that matters most for anyone trusting this — what is **not**
covered and why.

How the integration works is `explanation/stripe-payments.md`. How to run
any of it locally is `how-to/set-up-stripe.md`. Per-test detail for the
panel and storefront is `ui-tests.md`.

## The files

| File | Suite | Cases | What it owns |
|---|---|---|---|
| `tests/Feature/Payment/StripePaymentTest.php` | Feature | 36 | Action-level: intent creation, event handling, refund arithmetic |
| `tests/Feature/Payment/StripeWebhookSecurityTest.php` | Feature | 17 | The HTTP endpoint, adversarially |
| `tests/Feature/Payment/CheckoutTest.php` | Feature | 17 | The full cycle, cart → confirmation |
| `tests/Concurrency/StripeWebhookConcurrencyTest.php` | Concurrency | 4 | Two real processes on one payment |

CI: the three Feature files run on shard 2 (`tests/Feature/Payment` is a
directory entry); the concurrency file is named explicitly in shard `a`.

## Stripe is faked, deliberately

Every test binds a Mockery double over `StripeClient` through the container.
Not to avoid the network for its own sake, but because what is under test is
*this application's* arithmetic and state machine. A test that reached the
real API would prove Stripe works, which is not ours to prove and would make
the suite depend on network and credentials.

The mock targets `getService()`, not `__get()` — `StripeClient::__get()`
delegates to `getService()`, so mocking only `__get()` leaves the real
delegation running and produces a confusing
`BadMethodCallException: Received Mockery_…::getService(), but no
expectations were specified`.

## Verified by breaking it

CLAUDE.md: *"A test that has never been observed failing proves nothing."*
Each of these was run against a deliberately broken version and observed
red, then restored.

| What was broken | Result |
|---|---|
| `VerifyStripeWebhookSignature` replaced with a version that trusts the body | **8 of 11** security tests failed, including "refuses an unsigned request claiming a payment succeeded" |
| The `max(60, …)` tolerance floor removed | A **year-old replayed request was accepted with a 200** |
| The `UniqueConstraintViolationException` catch removed | Only 1 of 2 concurrent deliveries survived; the loser crashed |
| The check-then-act `exists()` guard substituted for the catch | **Still passed** — the UNIQUE index backstops it. Redundant, not dangerous, but it is the pattern CLAUDE.md forbids and would be the whole guard if the index were dropped |
| The `roles` strip in `EditUser` removed (adjacent work) | The two privilege-escalation tests failed |
| Multi-secret support reverted to a single secret | Only the mid-roll case failed; the "reject an unknown secret" and "retire a removed secret" cases still passed, so each targets its own behaviour |

The third and fourth rows together are the useful finding: **the UNIQUE
index is what prevents a double-apply; the catch is what makes the loser a
graceful 200 instead of a 500 Stripe retries for days.** They are not
interchangeable.

## Verified against the real Stripe API

Checked against live test-mode objects in the project's own sandbox
(`acct_1UAbac…`, `livemode: false`) through the Stripe MCP server, not just
against the SDK's generated stubs.

| Assumption | Real object | Result |
|---|---|---|
| `payment_intent.amount_received` exists and is distinct from `amount` | `amount: 2000`, `amount_received: 2000` | confirmed |
| `currency` is lowercase in API responses | `"currency": "usd"` | confirmed — the comparison lowercases both sides |
| `charge.payment_intent` carries the intent id | `"payment_intent": "pi_3UApwt…"` | confirmed — this is what `intentIdFrom()` reads for `charge.*` |
| `charge.amount_refunded` is an integer in minor units | `"amount_refunded": 0` | confirmed |
| `charge.refunded` is a separate boolean | `"refunded": false` | confirmed — and deliberately **not** used; it stays `false` during a partial refund and would misclassify |
| A charge carries PII we must not store | `billing_details`, `card.last4`, `receipt_url` all present | confirmed — `payloadFor()` stores none of them |

Three tests at the end of `StripePaymentTest` pin these shapes so an API
version bump that renames a field fails a test rather than silently
changing what the webhook reads.

## Verified against the real Stripe API, with a real 3-D Secure challenge

**Date of record:** 2026-09-04. The first end-to-end run against live test
credentials, replacing the "every test fakes `StripeClient`" gap this page
recorded until now. Sandbox account `acct_1U9BTmEinvfvnBsb`, `livemode:
false`.

| Step | Result |
|---|---|
| `placeOrder` → `CreateOrder` → `RecordPayment` → `CreateStripeIntent` | Order `ORD-000003`, intent `pi_3UC6dYEinvfvnBsb1QnqeUSP` |
| Intent as Stripe holds it | `requires_payment_method`, `amount: 1990`, `currency: eur` — matching the order's €19.90 |
| Confirm with `tok_threeDSecure2Required` | `requires_action`, `next_action.type = redirect_to_url` |
| The challenge itself | Stripe's hosted 3DS2 page, driven in a real browser, **Complete** clicked |
| Intent after the challenge | `succeeded`, `amount_received: 1990` |
| `payment_intent.succeeded` delivered to `POST /stripe/webhook` | **200**, `{"received":true}` |
| `payments` row | `pending` → **`paid`**, `paid_at` set, amount `19.90 EUR` |
| `payment_events` row | 1, keyed on the real `evt_3UC6dYEinvfvnBsb1DjXUIds` |

**The same event redelivered:** 200, and `payment_events` stays at **1** —
the UNIQUE index plus caught violation doing exactly what
`StripeWebhookConcurrencyTest` proves in isolation, here against a real
event id. **The same payload unsigned:** **400**. Both checked in the same
pass, so the idempotency result is not confused with a signature refusal.

The order was cancelled afterwards rather than deleted (§19 keeps order
history), which released the reservation — stock returned to its baseline
60/4.

### The trap this pass found: the CLI and the app on different accounts

`stripe listen` forwarded nothing for the whole run, and the failure was
silent in the worst way — the forwarder said `Ready!`, the app returned no
errors, and the payment simply stayed `pending` while Stripe said
`succeeded`.

The cause: the Stripe CLI was authenticated to **`acct_1UAbacHSYCrSsH7T`**
while the application's keys belong to **`acct_1U9BTmEinvfvnBsb`**. The CLI
was faithfully forwarding a different account's events. `stripe events list`
showed only that other account's history, which is what made it visible —
the app's own `$client->events->all()` had the real event all along.

Worth knowing because every symptom points elsewhere: it looks like a
webhook-handling bug, a signature problem, or a firewall issue, and it is
none of those. **Check `stripe config --list`'s `account_id` against
`$client->accounts->retrieve()->id` before debugging anything else.** The
signing secrets matching is not sufficient evidence that the two are the
same account — they matched here, because `whsec_…` is per-CLI-session, not
per-account.

Delivering the event directly — fetching it from the app's own account,
signing it with the app's own secret, and POSTing to the real endpoint
through the real middleware — is the workaround, and is what produced the
table above.

### Also confirmed by this run

- **`payments` has no `paid_amount` column.** The webhook records the paid
  figure by moving `status` and stamping `paid_at`; the amount lives in
  `amount`, set at intent creation from the order. Worth stating because a
  reasonable reader assumes otherwise.
- **API version skew is real but benign here.** The CLI reported
  `2026-08-26.dahlia`; the SDK pins `2026-07-29.dahlia`. The payload fields
  the webhook reads (`amount_received`, `currency`, `id`) were unchanged
  between the two.

## Refund verified against the real Stripe API

**Date of record:** 2026-09-05, same sandbox account as the 3DS run. A real
partial refund, issued and reconciled end to end:

| Step | Result |
|---|---|
| Payment before | `paid`, `refunded_amount: 0.00`, amount `80.73` |
| `$client->refunds->create()`, 2000 minor units | Stripe: `re_3UCKPKEinvfvnBsb0Fnx2ico`, `status: succeeded` |
| Real `charge.refunded` event delivered to `POST /stripe/webhook` | 200, `{"received":true}` |
| Payment after | **`partially_refunded`**, `refunded_amount: 20.00` |

This was the one part of the payment lifecycle the 2026-09-04 pass had not
exercised against the real API — `CreateStripeIntent` and the success webhook
were verified there; `RefundPayment` and the refund webhook path were not.
Together they now cover intent creation, 3DS confirmation, the success
webhook, and a partial refund, all against real Stripe objects rather than
the faked `StripeClient` the automated suite uses.

Delivered directly rather than through `stripe listen`, for the same reason
as the 3DS run: the CLI is authenticated to a different Stripe account than
the app's keys (`troubleshooting.md`, "Stripe says a payment succeeded and
the app still shows it pending"). Confirmed unchanged on 2026-09-05 — the
CLI still reports `acct_1UAbacHSYCrSsH7T` against the app's
`acct_1U9BTmEinvfvnBsb`.

## What is not tested, and why

This section is the point of the page. Each item is a real limit, not an
oversight.

### No test makes a real Stripe API call

**Why:** every test fakes `StripeClient`. Nothing in the suite proves Stripe
*accepts* the request shapes `CreateStripeIntent` and `RefundPayment` send —
only that the right values reach the client object.

**Partly closed:** the MCP verification above confirms the *response* field
names on real objects, and the SDK's own generated stubs (built from
Stripe's OpenAPI spec) type the request parameters. So a wrong field name
would likely fail static analysis or the stub types.

**Still open:** parameter *combinations*. `automatic_payment_methods`
together with a `metadata` block and an `idempotency_key` header is a shape
no test has ever sent to Stripe. The way to close it is a manual checkout
against test keys — `how-to/set-up-stripe.md` §4 — which takes about two
minutes and has not been done.

### The MCP could not create objects

The Stripe MCP session available while writing this exposed **read
operations only**: `GetPaymentIntents` and `GetChargesCharge` worked,
`PostPaymentIntents` returned *"Operation is not available."*

So the real-API verification is limited to inspecting objects that already
existed — created by someone running `stripe trigger` — rather than creating
one with this application's exact parameters and seeing it accepted. That is
the specific reason the gap above is only "partly closed".

### Gaps with no tests because the feature does not exist

Two of the four gaps this section originally listed have since been built
and are now covered — **signing-secret rotation** (4 cases in
`StripeWebhookSecurityTest`, verified by reverting to a single secret and
watching only the mid-roll case fail) and **dispute handling** (5 cases in
`StripePaymentTest`, including the expanded-`payment_intent` shape that
would otherwise have silently dropped real disputes).

What remains genuinely untestable here:

| Gap | Why there is nothing to test |
|---|---|
| **IP allowlisting** | Lives in nginx config, not in Laravel. A Pest test cannot assert on infrastructure the app does not own, and it is deliberately disabled locally because `stripe listen` forwards from your own machine. `docker/nginx/stripe-ip-allowlist.conf.example` documents it instead. |
| **Async webhook processing** | Processing is synchronous. Testing a queue that does not exist is not possible. |
| **Dispute *closing*** | `charge.dispute.closed` is unhandled, so moving back out of `Disputed` is a manual panel action. Testing it would mean specifying "what happens to an order when a dispute is won", which nobody has. |

The honest framing for what is left: these are recorded in
`explanation/stripe-payments.md` as gaps precisely because writing a test
first would mean writing the feature first, and each is a scope decision
that has not been made.

### The 80-second timeout is measured, not tested

`CurlClient::DEFAULT_TIMEOUT` was read from the vendor source to confirm the
number. No test holds a lock for 80 seconds to prove the consequence —
that would be an 80-second test asserting something already visible in one
constant.

### 3D Secure is not exercised

`confirmPayment` handles the redirect-based SCA flow, and card
`4000 0025 0000 3155` triggers it. That path is browser-side and needs a
real Stripe session; the suite has no browser driver. Manual only.

### `Money::percentageOf()` truncation is asserted, not fixed

`CheckoutTest` asserts VAT of `16.66` on a 100.00 gross line at 20%, where
the true value is 16.6667 and correct rounding gives 16.67. The method
narrows its double-scale intermediate with `bcadd`, which truncates.

The test documents current behaviour deliberately. This is shared money code
used by every total in the application; changing it moves existing figures
and is a rounding-policy decision, not part of the Stripe work.

### One historical flake, unidentified

A full-suite run once reported `1 failed, 977 passed`; the next run passed
978, and the Concurrency suite passed 48/48 standalone. Never reproduced,
never identified. Most likely a timing-barrier concurrency test —
`RaceHelper` documents that sensitivity and exposes `RACE_BARRIER_SECONDS`
for loaded machines.

## Bugs these tests caught

All in code written during the same session, all found by a test rather than
by review:

1. **Cumulative vs delta.** Stripe's `amount_refunded` is a running total;
   `TransitionPaymentStatus` accumulates. Refund 25 then 40 and the row read
   65 instead of 40.
2. **Successive partial refunds dropped.** An early return for "target
   status equals current status" discarded every partial refund after the
   first, contradicting `PaymentStatus`'s own matrix.
3. **No-op events indistinguishable from applied ones.** An event that
   changed nothing was recorded with `note = null`.
4. **A guard that compared a value against itself.** `amount_received ??
   amount` fell back to the figure `CreateStripeIntent` had itself set, so
   the amount check passed unconditionally.
5. **A documented currency check that did not exist.** The docblock claimed
   it; the code did not do it.
6. **Two security tests that passed with the guard deleted** (adjacent
   `UserResource` work) — they exercised a disabled form field rather than
   the server-side seam.
