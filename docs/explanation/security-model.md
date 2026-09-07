# Where authorization actually holds, and where it does not

ADR-0006 decides who checks what. ADR-0007 decides how an Action's actor
parameter works. Neither says, in one place, what the combination protects
against and what it does not — which is the question that comes up whenever a
new call site is about to reach an Action. This page is that picture, current
as of 2026-08-16.

Every layer a request or a piece of data actually passes through, in order —
IP allowlist, host configuration, client- and server-side validation, rate
limiting, DB-level constraints — is drawn separately:
[view source](../reference/diagrams/security-defense-layers/security-defense-layers.puml) ·
[view PDF](../reference/diagrams/security-defense-layers/security-defense-layers.pdf) —
not every layer applies to every route, and the diagram says which ones do.

## The trust boundary is the call site, not the Action

An Action with a null actor is a fully privileged write primitive. `null`
means "the application, acting on its own behalf" (ADR-0007), and every domain
rule still runs — but the policy check does not. Nothing inside
`AddProductImage` or `TransitionOrderStatus` can tell a legitimate system
caller from a caller that should have passed a real user and did not.

That means authorization is a property of **the call site**, not of the
Action. The Action enforces a rule *given* an actor; deciding whether an actor
is required at all happens one layer up, in whatever code is about to call
`handle()`.

Today every production call site is a Filament page or relation manager,
reading `auth()->user()` typed as non-null `User`, behind
`canAccessPanel()` (ADR-0006 §1). So every reachable call is already
authenticated before it reaches an Action, and null is reachable only from
tests and — once slice 6 lands — the Stripe webhook.

## What ADR-0007's amendment actually fixed

Before 2026-08-16, `?User $actor = null` meant a caller who *forgot* the
parameter silently got system privileges. That was never an externally
exploitable hole, because no external caller could reach the omission — but it
was a defect waiting for the day a call site stopped being Filament-only.

Dropping the default (ADR-0007) converts the omission into an
`ArgumentCountError`. It does not add a check the Action didn't have before;
it removes the one way authorization could be skipped **by accident** rather
than by a deliberate, greppable `actor: null`.

## What this does not defend against

Two things, both worth naming so nobody assumes null-actor is a security
control:

**Code execution on the box.** An attacker with `docker compose exec`, a
compromised queue worker, or RCE in some other dependency does not need to
find a null-actor call site — they can already run `DB::table('products')
->delete()`, or read `.env`. No Action-level check defends against that,
because the check runs inside the same PHP process as the attack.

**A caller that decides, on its own, to pass a real actor's credentials
through where they don't belong** — e.g. a bug that reuses a queued job's
system context for a customer-triggered write. ADR-0007's mechanism only
catches *omission*; a call site that explicitly and wrongly passes `null` (or
wrongly passes a real user with no bearing on the resource) is a logic bug the
Action cannot see.

## Where null-actor becomes genuinely reachable: the webhook

Slice 6 (`HandleStripeWebhookEvent`) is the one place this stops being
theoretical. A webhook is unauthenticated, external, HTTP input — and it
legitimately calls Actions with a null actor, because there is no Stripe user
in this system's `users` table. `TransitionPaymentStatus` and
`TransitionOrderStatus`, both null-actor, both privileged, sit directly behind
it.

There, the entire perimeter is **signature verification in middleware**
(CLAUDE.md: "Stripe webhook: CSRF-excluded and signature-verified. One without
the other is a free-products vulnerability.") — not anything inside the
Actions. When that lands, the signature check needs the same proof every
other guard in this codebase gets: delete it, watch a forged webhook succeed,
restore it. See `how-to/troubleshooting/concurrency-and-testing-races.md`,
"A concurrency test passes whether or not the lock is there" for the general
shape of that proof, applied here to an authorization check instead of a
lock.

## If a future call site needs null-actor to be a real refusal

Nothing today requires this, and nothing should be built for it speculatively.
If a call site ever needs an Action to refuse `null` in an HTTP context while
still allowing it from the console/queue, the mechanism is
`app()->runningInConsole()` — true for CLI and queue workers, false for a web
request. The webhook is the wrinkle: it is HTTP *and* legitimately null, so it
would need an explicit, single, reviewable opt-out rather than a blanket
allowance. Worth deciding when slice 6 is actually being built, against the
real shape of the webhook controller, not in the abstract.

**Resolved, now that the webhook exists.** No opt-out mechanism was needed.
`HandleStripeWebhookEvent` passes a null actor to `TransitionPaymentStatus`
— Stripe is the actor and holds no permissions — and what stops an attacker
reaching that path is not an actor check at all but
`VerifyStripeWebhookSignature`, which refuses the request before any Action
is constructed. The null actor is safe there precisely because the request
was authenticated one layer earlier, by something other than a permission.

## The Stripe webhook

`POST /stripe/webhook` is the only route in this application that is
unauthenticated, CSRF-exempt, reachable from the public internet, and able
to move money-bearing state. It deserves its own section.

### Why it is CSRF-exempt, and what replaces CSRF

Stripe is not a browser and carries no session token, so CSRF protection is
not merely unhelpful here — it would reject every legitimate delivery. The
route is registered in `bootstrap/app.php`'s `withRouting(then:)` with **no
middleware group at all**, so it never enters the `web` group: no session,
no cookies, no CSRF token, no `EnsureAccountIsActive`. That is deliberate,
and it is stronger than exempting the route from CSRF while leaving it in
the group, because there is nothing to accidentally re-add.

What replaces CSRF is `VerifyStripeWebhookSignature`. CLAUDE.md states the
pairing as a single rule — "CSRF-excluded **and** signature-verified; one
without the other is a free-products vulnerability" — and the exemption
above is only safe because the signature check is unconditional.

### What the signature actually proves

`Webhook::constructEvent()` recomputes an HMAC-SHA256 over
`{timestamp}.{raw body}` with the endpoint's signing secret. That yields
three properties at once:

| Property | Because | Attack it stops |
|---|---|---|
| Authenticity | only a secret-holder can produce the MAC | an anonymous POST announcing `payment_intent.succeeded` |
| Integrity | the body is inside the MAC | keeping a real signature and editing the amount |
| Freshness | the timestamp is inside the MAC, and checked against a tolerance | replaying a captured request days later |

Two details matter and are easy to get wrong:

- **The raw body, not a re-parsed one.** The MAC covers exact bytes;
  re-encoding JSON reorders keys and breaks it. The middleware reads
  `$request->getContent()` and hands the *verified* event object forward on
  the request, so the controller never re-parses. Verifying one
  representation and acting on another is the classic version of this bug.
- **It fails closed.** A blank `STRIPE_WEBHOOK_SECRET` returns 500 and
  refuses every webhook rather than skipping verification. A misconfigured
  deploy quietly accepting unsigned webhooks is the most likely way to
  reach the vulnerability this class exists to prevent.

### Freshness is not enough on its own

The tolerance window means a captured request *can* still be replayed inside
it, and the signature will verify — it is a genuine Stripe request. Replay is
made harmless by a second, independent mechanism:
`payment_events.stripe_event_id` is `UNIQUE`, and
`HandleStripeWebhookEvent` inserts that row first, inside the transaction,
catching the violation rather than checking for it. §37 #11.

Which half does what was established by removing each in turn against
`tests/Concurrency/StripeWebhookConcurrencyTest.php`:

- Removing the **UNIQUE index** is what would allow a double-apply. It is
  the actual guard.
- Removing the **catch** does not allow a double-apply — the index still
  refuses the second insert — but the losing delivery then crashes with an
  unhandled exception and returns 500, which makes Stripe retry a request
  that can never succeed. The catch is what makes a duplicate *graceful*,
  not what makes it safe.
- Replacing the catch with a check-then-act `exists()` query still passed
  the concurrency test, because the index backstops it. It is redundant
  rather than dangerous here — but it is the pattern CLAUDE.md forbids, and
  it would be the whole guard if the index were ever dropped.

### The adversarial tests

`tests/Feature/Payment/StripeWebhookSecurityTest.php` is written from the
attacker's side: what does someone who can POST to this URL, without the
signing secret, have to do to get an order marked paid? Seventeen cases,
each naming one attempt — unsigned, wrong secret, malformed header, body
edited after signing, a signature captured for one intent replayed against
another, an expired timestamp, an unconfigured secret, whether error
responses leak which PaymentIntent ids exist, and four cases covering
signing-secret rotation (below).

They were validated by replacing the middleware with a deliberately
vulnerable version that trusts the body without verifying: **8 of the
first 11 failed**, including "refuses an unsigned request claiming a
payment succeeded". The three that still passed are correctly scoped —
idempotency depends on the UNIQUE index rather than the signature, and
route shape and HTTP method are unaffected by the middleware's contents.

### Signing-secret rotation

Stripe recommends rolling the webhook signing secret periodically. During a
roll it keeps the old secret valid for up to 24 hours and signs each event
with *every* active secret, putting one signature per secret in the same
header. A single-secret verifier rejects events signed only with the new
one — silently dropping real payments for the length of the roll.

`VerifyStripeWebhookSignature` accepts a list: `STRIPE_WEBHOOK_SECRET` plus
an optional `STRIPE_WEBHOOK_SECRET_PREVIOUS`, tried in order. Set the latter
to the outgoing secret for the overlap window, then remove it — leaving it
set indefinitely just widens the accepted set for no benefit. Verified by
reverting to a single secret: only the mid-roll case failed, while "reject
an unknown secret" and "retire a removed secret" stayed green, confirming
each test targets its own behaviour rather than one broad assertion masking
the others.

### Disputes

A customer can dispute a charge with their bank long after the money moved.
`charge.dispute.created` maps to `PaymentStatus::Disputed`, reachable only
from `Paid` and `PartiallyRefunded` — the states where money actually
arrived — and not terminal: a dispute won by the merchant returns to
`Paid`, one lost ends at `Refunded`.

Two guards that would have been wrong by default: the paid-amount check
does **not** apply to a dispute, because Stripe documents a dispute's
amount as "usually the amount of the charge, but it can differ" — a partial
dispute is legitimate, not a mismatch to refuse. And
`Dispute::$payment_intent` is typed `null|PaymentIntent|string` by Stripe —
an id normally, an expanded object when expansion was requested — so
reading it as a string only would have silently ignored every dispute
delivered with an expanded payload.

## Summary table

| Question | Answer | Where |
|---|---|---|
| Is this person staff at all? | `canAccessPanel()` role check | ADR-0006 §1 |
| May their role do this kind of thing? | Permission row | ADR-0006 §2 |
| May they do it to *this record*? | Policy | ADR-0006 §3 |
| Does the administrator bypass all of it? | `Gate::before` | ADR-0006 §4 |
| Who is allowed to skip the policy entirely? | Nullable, no-default `$actor` | ADR-0007 |
| What stops an attacker reaching null-actor over HTTP? | `VerifyStripeWebhookSignature` — the request is refused before any Action is built | CLAUDE.md, this page |
| What stops a *replayed* genuine webhook? | `payment_events.stripe_event_id` UNIQUE, not the signature | §37 #11, this page |
