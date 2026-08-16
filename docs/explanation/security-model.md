# Where authorization actually holds, and where it does not

ADR-0006 decides who checks what. ADR-0007 decides how an Action's actor
parameter works. Neither says, in one place, what the combination protects
against and what it does not — which is the question that comes up whenever a
new call site is about to reach an Action. This page is that picture, current
as of 2026-08-16.

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
restore it. See `how-to/troubleshooting.md`, "A concurrency test passes
whether or not the lock is there" for the general shape of that proof, applied
here to an authorization check instead of a lock.

## If a future call site needs null-actor to be a real refusal

Nothing today requires this, and nothing should be built for it speculatively.
If a call site ever needs an Action to refuse `null` in an HTTP context while
still allowing it from the console/queue, the mechanism is
`app()->runningInConsole()` — true for CLI and queue workers, false for a web
request. The webhook is the wrinkle: it is HTTP *and* legitimately null, so it
would need an explicit, single, reviewable opt-out rather than a blanket
allowance. Worth deciding when slice 6 is actually being built, against the
real shape of the webhook controller, not in the abstract.

## Summary table

| Question | Answer | Where |
|---|---|---|
| Is this person staff at all? | `canAccessPanel()` role check | ADR-0006 §1 |
| May their role do this kind of thing? | Permission row | ADR-0006 §2 |
| May they do it to *this record*? | Policy | ADR-0006 §3 |
| Does the administrator bypass all of it? | `Gate::before` | ADR-0006 §4 |
| Who is allowed to skip the policy entirely? | Nullable, no-default `$actor` | ADR-0007 |
| What stops an attacker reaching null-actor over HTTP? | Nothing today; will be signature verification at the webhook (slice 6) | CLAUDE.md, this page |
