# Chaos / failure-injection testing — what happens when a dependency actually fails

Part of the [testing reference](README.md). Deliberately induced failures
— Stripe unreachable, a real MySQL connection killed mid-transaction, the
mail service down — with what the customer and the database actually show
at each one, not what the logs claim. Distinct from
[performance-testing.md](performance-testing.md) (slow, not failed) and
from `tests/Concurrency`'s own races (two correct requests interleaving,
not one request failing outright).

**Date of record:** 2026-09-14.

## Scope, and why these four

Named and scoped before starting, not chosen as convenient examples: Stripe
timing out mid-checkout, a MySQL connection dropped mid-transaction, the
mail service unreachable, and a customer's own internet connection dropping
mid-checkout — covering the two directions a chaos test can look (an
external dependency failing on *us*, and *our own* infrastructure failing
under us) plus the case that is neither — the client simply vanishing,
which turns out to only have two possible shapes once reasoned through:
either the request had already committed server-side (indistinguishable
from a server crash — the MySQL-kill test below covers it) or it never
reached the server at all (nothing to test, nothing changed). The
interesting version of "connection drops" is not either of those — it is a
request that *succeeded* and then nothing further ever happens, which the
fourth section below covers as its own case.

## Finding 1 — Stripe unreachable mid-checkout produces an uncaught 500, verified live and by test

**The gap.** `CreateStripeIntent::handle()` calls
`$this->stripe->paymentIntents->create(...)` with no `try`/`catch` around
it. A real network failure — timeout, DNS failure, TLS refusal — surfaces
from `stripe-php` as `Stripe\Exception\ApiConnectionException`, which
extends `ApiErrorException`, which extends the SDK's own base `Exception`
— never `InvalidArgumentException` or `RuntimeException`.
`CheckoutPage::placeOrder()`'s `catch` clause names exactly those two and
nothing else (`catch (InvalidArgumentException|RuntimeException $e)`), so
Stripe's exception is not caught there either. It propagates out of the
Livewire component call as an unhandled exception — a generic Laravel 500
page, not the clean form error every other checkout refusal produces.

**Proven, not just read.** `tests/Feature/Payment/CheckoutTest.php`'s
`'surfaces an uncaught 500 when Stripe is unreachable during checkout — a
real, unfixed gap'` mocks `paymentIntents->create()` to throw the real
exception class and asserts `placeOrder()` throws it uncaught, rather than
being swallowed into a form error the way every other refusal is. Named to
say so in its own title, so a future change that makes it pass silently
(catches the exception somewhere) reads as "the gap this documented is
closed," prompting an update to this page rather than a quietly-stale
finding.

**Not corrupting, at least.** A second test,
`'rolls back the order and releases stock even when Stripe fails
uncaught'`, confirms the half that matters most: `CreateOrder` and
`RecordPayment` ran inside the same `DB::transaction()` the Stripe call is
still inside (`CheckoutPage::placeOrder()`'s own wrapping), so PHP's
ordinary exception-unwinding rolls the whole transaction back — no order
row, no reserved stock left behind. This is standard `DB::transaction()`
behaviour, not something `CheckoutPage` does on purpose for this case, and
it is verified here rather than assumed: **`Order::count()` is 0 and the
variation's `reserved_quantity` is 0** after the uncaught throw, checked
directly against the database.

**Not fixed.** The customer sees a raw 500 instead of "Payment could not
be started, please try again" — a real UX gap, not a data-safety one. The
fix is adding Stripe's exception hierarchy to `CheckoutPage::placeOrder()`'s
catch clause (or a narrower `ApiConnectionException`-specific one, since a
genuine `ApiErrorException` for a different reason — a bad request shape —
arguably *should* surface loudly rather than read as "try again"). Left
as a scoped, named gap rather than patched inline during a testing pass.

## Finding 2 — a real MySQL connection killed mid-transaction leaves no partial state

**What was actually done, not just asserted.** A second, genuinely
separate `PDO` connection (`tests/Concurrency/CreateOrderConnectionLossTest.php`)
issues `KILL <connection_id>` against the connection running
`CreateOrder`'s transaction, timed via a `DB::listen()` callback that fires
the instant the `orders` INSERT has been observed — after real work has
already happened inside the transaction (the order row exists, about to be
followed by order items and a stock reservation), which is the case worth
proving: a connection loss late enough that a naive implementation would
already have something to lose. This reaches MySQL's own
connection-teardown code over the real network protocol; it is not an
exception thrown from application code standing in for one.

**Result: nothing leaks.** `Order::query()->count()` is 0,
`OrderItem::query()->count()` is 0, and the variation's
`reserved_quantity` is 0 — checked after Laravel transparently reconnects
on the next query, the same reconnect behaviour a real request would rely
on. `DB::transaction()`'s rollback guarantee holds against a genuine
network-level connection loss exactly as it holds against a thrown
`RuntimeException`, which is the property this test exists to confirm
rather than assume from documentation.

**Verified red before green**, per this project's own testing discipline:
temporarily disabling the `KILL` trigger (so the transaction completes
normally) makes the test's own explicit failure-guard fire — proving the
test would catch a real regression, not merely pass by construction.

## Finding 3 — mail service outage: bounded retry works correctly, but a failed job has no recovery path

**What triggered this pass.** Discovered by accident first, the same day —
the local `queue` container was crash-looping because `mailpit` was simply
not running (see
[`troubleshooting/infra-and-environment.md`](../../how-to/troubleshooting/infra-and-environment.md)'s
"The `queue` container crash-loops" entry). This section is the deliberate,
re-induced version of that accident, done carefully enough to actually
understand the mechanism rather than just the symptom.

**The mechanism, precisely** (`mailpit` stopped, a `ContactMessageReceived`
mailable queued fresh, then watched):

1. The job runs, fails in ~90ms with a real
   `Symfony\Component\Mailer\Exception\TransportException: Connection
   could not be established with host "mailpit:1025"` — the actual
   exception recorded in `failed_jobs.exception`, not inferred.
2. The worker **process** exits on that failure — a Symfony Mailer
   behaviour, not an application bug. `docker-compose.yml`'s
   `restart: unless-stopped` on the `queue` service brings it back within
   about a second.
3. This repeats. Laravel's own `--tries=3` (set in both
   `docker-compose.yml`'s `queue:work --tries=3 --max-time=3600 --sleep=1`
   and the documented Railway worker command in `deploy-and-host.md`) is
   tracked on the job row itself, not in the worker process's memory, so
   the count survives each restart correctly.
4. After exactly 3 attempts, the job lands in `failed_jobs` — **verified**:
   `failed_jobs` was 0 before, 1 after, with the real exception attached.

**Customer-facing requests are never affected**, by construction: both
`OrderPlaced` (checkout) and `ContactMessageReceived` (the contact form)
are dispatched with `->queue()`, not `->send()` — the HTTP request that
queues them returns its normal response the instant the `jobs` row is
written, regardless of whether the mail ever actually sends. This is
already correct, not a finding.

**The real gap: nothing retries a failed job once the outage ends.**
`failed_jobs` is a dead end today — no scheduled command, no listener, no
operator-facing alert reads it. A contact-form message or an order
confirmation that failed during a transient outage (a mail relay blip,
not just a stopped local container) is **silently lost forever** unless a
human happens to run `php artisan queue:retry` by hand after noticing.
Nothing in this project currently notices. Not fixed here — recorded as
the actual gap this chaos pass exists to find, distinct from (and more
useful than) the crash-loop symptom that first surfaced it.

## Finding 4 — a real Stripe order, abandoned mid-connection, is correctly found and released — proven end to end, not just at the seams

**The gap this closes.** `ExpireUnpaidOrdersTest.php`'s own `unpaidOrder()`
helper builds an `AwaitingPayment` order directly through factories —
never through a real `CheckoutPage` submission, never with a real (mocked)
Stripe response. The sweep (`ExpireUnpaidOrders`) is thoroughly proven in
isolation; nothing proved the seam between "a real checkout reaches
AwaitingPayment with a real intent id" and "the sweep finds and releases
exactly that order."

**What "the connection drops" actually means here.** Unlike Findings 1–2,
this path does not fail — `CreateStripeIntent` returns normally, a real
(mocked) intent id is stored, the order legitimately reaches
`AwaitingPayment` exactly as ADR-0022 designed. What a dropped connection
changes is everything *after*: the browser never receives the response, so
it never confirms the `PaymentIntent`, and the order sits there
indefinitely unless something sweeps it.

**Proven end to end**
(`tests/Feature/Payment/CheckoutTest.php`'s `'places a real Stripe order
through checkout, then the same order is found and released once
abandoned past the TTL'`): a real `CheckoutPage` submission reaches
`AwaitingPayment` with a real intent id and a real reservation
(`reserved_quantity` 2, matching the cart) — then, simulating "nothing
else ever happens" by backdating the `AwaitingPayment` history row past
the TTL (the same technique `ExpireUnpaidOrdersTest.php` already uses,
since the sweep ages off that row, not `orders.created_at`) —
`ExpireUnpaidOrders::handle()` finds exactly this order, cancels it, and
the reservation returns to 0. One real request, one real sweep, the
seam proven rather than assumed from two separately-passing test files.

## What this page is not

Not exhaustive — four failure points, chosen and scoped before starting,
not a general fuzzing pass across every dependency this application has.
Not a fix for Findings 1 and 3, both left as named, scoped gaps rather
than patched inline — Finding 1 needs a design decision (what the customer
actually sees), Finding 3 needs one too (auto-retry? an alert? how long
before a lost email is acceptable). Finding 2 needed no fix — it was a
test of an existing guarantee, and the guarantee held.
