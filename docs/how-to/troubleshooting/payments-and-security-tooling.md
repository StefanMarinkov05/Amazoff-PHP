# Troubleshooting — Stripe and security-scanner tooling

Part of the [troubleshooting index](../troubleshooting.md). Errors specific
to the Stripe integration and its supporting CLI/MCP tooling, and to running
OWASP ZAP against this app.

---

## Stripe says a payment succeeded and the app still shows it pending

**Symptom.** A card payment completes at Stripe — `stripe listen` is running
and said `Ready!`, the PaymentIntent reads `succeeded` with a non-zero
`amount_received` — but `payments.status` stays `pending`, `paid_at` is null,
and `payment_events` is empty. No error anywhere: not in `laravel.log`, not
in the web server's access log (which shows **zero** hits on
`/stripe/webhook`), not in the forwarder's own output.

The same account mismatch shows up through the **Stripe MCP connector**
too, not just the CLI: `list_available_accounts_or_orgs` (or whatever the
client calls it) reports one `stripe_context`, and any write made through it
— creating an intent, confirming one, fetching an event — silently operates
on that account instead of the one the app's keys belong to. Confirmed
2026-09-06: an MCP session connected to `acct_1UAbacHSYCrSsH7T` while
`STRIPE_SECRET` in `.env` belonged to `acct_1U9BTmEinvfvnBsb` — the same pair
as the CLI incident below, on a different machine, months apart, which is
itself evidence for the cause described next.

**Cause.** Whatever tool is being used — CLI or MCP — is authenticated to a
**different account** than the application's API keys belong to. `stripe
listen` is forwarding faithfully — just some other account's events; the MCP
connector is reading/writing faithfully — just against some other account's
data. Confirmed 2026-09-04 (CLI): the tool sat on `acct_1UAbacHSYCrSsH7T`
while `STRIPE_SECRET` belonged to `acct_1U9BTmEinvfvnBsb`. Confirmed again
2026-09-06 (MCP), same two account ids. **Confirmed a third time, 2026-09-13
(MCP):** `list_available_accounts_or_orgs` offered only `acct_1UAbacHSYCrSsH7T`
in that session, with no way to reach `acct_1U9BTm...` from it —
`GetWebhookEndpoints` against that account correctly returned empty, which
was briefly (and wrongly) read as "this app has no webhook configured."
The fix used this time was the one this file already recommends below
("verify without touching either tool"): the app's own `StripeClient` inside
the container confirmed the canonical account directly
(`accounts->retrieve()->id`), and the actual endpoint was created by hand in
the dashboard at the account-scoped URL
(`dashboard.stripe.com/acct_1U9BTmEinvfvnBsb/...`), not through the
connector. Three occurrences over a week, same two ids, is no longer a
one-off — treat any MCP-reported "no webhook/no data" result on this project
as unverified until the account id is checked against
`acct_1U9BTmEinvfvnBsb` specifically.

**Likely root cause, on a team project: an individual auth, not a shared
one.** Both the CLI and the MCP connector authenticate *per developer*, not
per repository — `stripe login` and an MCP OAuth grant both attach to
whichever Stripe account the person doing it is signed into at the time,
which has nothing to do with which account issued the keys in the team's
`.env`.

**`acct_1UAbacHSYCrSsH7T` is not an empty scratch account — it is a second,
actively-used Stripe sandbox also named "Amazoff".** Checked 2026-09-06: its
dashboard shows real-looking activity (`€1,994.53` gross volume, a nonzero
EUR balance) that does **not** come from this app — the app's own database
at the time held only 2 payments with a real Stripe intent, both opened
during that session's testing. That rules out "someone logged into it once
by accident and it happened to have leftover data"; the volume on
`acct_1UAbac...` was produced by something else, running against that
account, believing it to be the project's.

**Resolved, 2026-09-06: not a missing-access problem — the developer has
both accounts, and Stripe's own UI made that hard to see.** The "Accounts"
list page (`dashboard.stripe.com` → account switcher → "The list of
accounts of which you are a member") showed only `acct_1UAbacHSYCrSsH7T`
for the developer investigating this, which read as "I don't have
`acct_1U9BTmEinvfvnBsb`." But navigating directly into a Workbench view
(`dashboard.stripe.com/acct_1U9BTmEinvfvnBsb/test/workbench/...`) loaded
successfully, showing **the same display name, "Amazoff sandbox," on a
different account id** — and, conclusively, this session's own test
activity: an `invalid_request_error` on
`pi_3UCQm5EinvfvnBsb0ZceOS...`, the exact intent id
`demo:stripe-payments` opened during this session's Stripe verification.
That proves `acct_1U9BTm...` is real, reachable by this developer, and is
the account every seeder/Action/webhook test in this session actually ran
against.

**So there are two distinct Stripe sandboxes, both named "Amazoff," both
reachable by the same developer login** — the "Accounts" list apparently
did not surface both (scoping or a caching quirk on Stripe's own UI, not
investigated further). `.env` is correct and needs no change. The only
actual bug is the CLI/MCP tooling defaulting to the *other* one of the
developer's two accounts.

**Practical trap this leaves behind: identical account names.** Checking
`stripe config --list | grep account_id` (or the app's own
`accounts->retrieve()->id`) against the *id*, not the display name, is the
only reliable check from here on — two sandboxes sharing one name is
confirmed to exist on this project, not a hypothetical.

The teammate identified separately as `acct_1UAzVTK9AR8y1wsZ` is unrelated
to either of the two above and still not referenced in this app's
configuration — no action needed there.

**`acct_1U9BTmEinvfvnBsb` is confirmed canonical — it's what `.env`
contains, and both developers who checked so far can reach it.** Anyone
setting up the CLI or an MCP connector for this repo should authenticate
against that id specifically. Checking by name ("Amazoff sandbox") is not
enough — confirmed above to collide with a second account under the same
login.

**The signing secrets matching is not evidence the accounts match.** The
`whsec_…` a `stripe listen` session prints is generated per session, so
copying it into `.env` makes the signature check pass for whatever events do
arrive — while the events you care about are never sent at all.

**Fix, CLI.** Compare the two directly:

```bash
stripe config --list | grep account_id
docker compose exec -T app php artisan tinker --execute='echo app(Stripe\StripeClient::class)->accounts->retrieve()->id;'
```

If they differ, re-authenticate the CLI against the right account
(`stripe login`), or point `.env` at the account the CLI already holds.

**Fix, MCP.** List the connector's accounts and compare the same way:

```
list_available_accounts_or_orgs   → stripe_context, e.g. acct_1UAbac...
docker compose exec -T app php artisan tinker --execute='echo app(Stripe\StripeClient::class)->accounts->retrieve()->id;'   → acct_1U9BTm...
```

If they differ, the connector needs re-authorizing against
`acct_1U9BTmEinvfvnBsb`. The `manage_stripe_accounts` MCP tool returns a
Stripe-hosted URL for this — open it, sign into the right account (or add it
alongside the wrong one), and re-run `list_available_accounts_or_orgs` to
confirm. This is an account-level authorization change on Stripe's side;
nothing in the repo or `.env` causes or fixes it, and there is no way to
force a teammate's already-authorized session to switch — each person's CLI
login and MCP grant only they can re-point, from their own machine.

**To verify the handler itself without touching either tool**, fetch the
event from the app's *own* account (inside the container, using the app's
own key — this sidesteps the CLI/MCP mismatch entirely rather than working
around it), sign it with the app's *own* secret, and POST it to the
endpoint. This is the real middleware, real bytes, not a simulation:

```php
// Everything below runs with the app's own StripeClient, so it always
// targets the right account regardless of what the CLI or MCP hold.
$stripe = app(Stripe\StripeClient::class);

// 1. Open an intent the normal way, or use one demo:stripe-payments made.
$intent = app(App\Actions\Payment\CreateStripeIntent::class)->handle($payment);

// 2. Confirm it with a test card. automatic_payment_methods needs a
//    return_url for a server-side confirm outside the browser flow.
$intent = $stripe->paymentIntents->confirm($intent->stripe_payment_intent_id, [
    'payment_method' => 'pm_card_visa',
    'return_url' => 'https://example.com/return',
]);

// 3. Fetch the resulting event and sign it exactly as Stripe would.
$event = $stripe->events->all(['type' => 'payment_intent.succeeded', 'limit' => 1])->data[0];
$payload = json_encode($event->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$ts = time();
$sig = hash_hmac('sha256', "{$ts}.{$payload}", config('services.stripe.webhook_secret'));
// 4. POST $payload to /stripe/webhook with header:
//    Stripe-Signature: t={$ts},v1={$sig}
```

Verified end to end 2026-09-06 this way: intent confirmed
(`amount_received` matching the payment row to the minor unit), webhook
returned `200 {"received":true}`, and `payments.status` moved to `paid` with
a `payment_events` row recorded — proving the full path without either tool
needing to hold the right account.

**Why it recurs.** Every symptom points somewhere else — a webhook-handling
bug, a signature mismatch, a container networking problem. Neither the CLI's
output nor the MCP connector's tool list names the account by default, and a
developer with more than one Stripe account (a personal sandbox and a team
one) can switch the app's keys without either tool following — worse on a
team project, where the account a *teammate* authenticated months ago for an
unrelated reason can be the one still attached when someone else hits this.

**Prevention.** Check the account pair before debugging a missing webhook or
a failed MCP write, and re-check it whenever `STRIPE_SECRET` changes or a
new person authenticates a tool for this repo. `stripe listen` printing
`Ready!`, or an MCP tool call succeeding, proves a connection opened — not
that it is the right account. State the canonical account
(`acct_1U9BTmEinvfvnBsb`) explicitly when onboarding a teammate to Stripe
tooling on this project, rather than letting each person's tool default to
whatever they last signed into.

---

## A ZAP full scan against `/orders/track` gets OOM-killed at `DomXssScanRule` below a 12 GB cap

**Resolved 2026-09-06.** A fifth attempt with `-m 12g --memory-swap 12g`
completed cleanly — `docker inspect`: `OOMKilled: false`, exit code `2`
(ZAP's own "warnings found" code, not a crash), a real, non-empty log, and
both `-r`/`-x` report files written. All 136 active-scan rules passed with
0 alerts in every injection class; 5 WARN-NEW findings, all already-triaged
header/config categories. Total wall-clock: ~1h33m (18:41–20:14), far
longer than SEC-005's 48-minute `/catalogue` run despite a much smaller
attack surface — consistent with the extra headroom being genuinely used
during `DomXssScanRule`'s browser launch, not wasted. `12 GB` is now the
memory-cap recommendation in `set-up-security-and-quality-tools.md`.
Full details in `reference/testing/security-testing/what-held.md`'s "Other
checks" table. The symptom, root cause, and history of failed attempts below are
kept for the next time this rule needs debugging on a smaller machine —
this specific target is fixed.

**Symptom.** `zap-full-scan.py` against `/orders/track` (two form fields)
runs cleanly for roughly 9 minutes — spider, then every active-scan rule up
through the SQLi timing variants, all with 0 alerts — then memory climbs
sharply and the container dies with no `-r`/`-x` report written. Confirmed
across four attempts on 2026-09-06, including with no Docker memory cap
(host-wide exhaustion), a `-m 6g --memory-swap 6g` cap, and a
`-m 10g --memory-swap 10g` cap — all four die at the same point. A fifth
attempt at `-m 12g --memory-swap 12g` did not die — see "Resolved" above.

**Root cause, confirmed by hard evidence, not inferred.** The fourth attempt
was run detached (`docker run -d`, no `--rm`) specifically so its exit
state could be inspected directly rather than guessed from a piped log.
`docker inspect zap-orders-track-4 --format '{{.State.OOMKilled}}
{{.State.ExitCode}}'` returned **`true 3`** — a definitive OOM kill, the
first non-inferred proof across all four attempts. `docker logs` (596
lines, only readable once the container had exited — see the buffering
note below) showed the scan died immediately after
`SqlInjectionPostgreSqlTimingScanRule` completed and `DomXssScanRule`
started, right after `"Failed to configure ZAP extension on browser
launch"` WARN messages. Every rule before that point — PathTraversal,
RemoteFileInclude, ShellShock, HeartBleed, SourceCodeDisclosure (×2), RCE,
ExternalRedirect, ServerSideInclude, reflected/persistent XSS (×3), generic
SQLi plus four DB-specific timing SQLi variants — completed cleanly with 0
alerts. `DomXssScanRule` is the one active-scan rule that needs a real
headless browser (Firefox via geckodriver) rather than ZAP's own HTTP
client; that browser launch is what pushes memory past both the 6 GB and
10 GB caps tried so far, on a host confirmed idle by two `docker stats`
samples five seconds apart (NET I/O and PID count still climbing, not
stalled) during a monitored earlier attempt.

**This supersedes an earlier version of this entry.** A prior revision
generalised the authenticated-scan's `spider.thread=1`/
`scanner.threadPerHost=1` fix (which exists because default concurrency
races `AuthenticateSession` middleware — a correctness bug, not a memory
one) onto this unauthenticated failure; that was wrong and has been
retracted here. A revision after that left "ambient memory pressure from
concurrent host work" as the leading unconfirmed theory. That theory is
also now superseded: the failure reproduces identically at both 6 GB and
10 GB container caps on a host confirmed idle, at the exact same rule every
time — that is a `DomXssScanRule`-specific memory cost, not ambient
pressure. Neither earlier theory should be reapplied to a future ZAP
failure without first checking whether the log dies at the same point.

**`docker logs` showing 0 lines while the container is still running is not
a stall.** `zap-full-scan.py` runs under Python with no TTY attached via
`docker run -d`, so stdout is fully buffered and nothing reaches `docker
logs` until the process exits or the buffer flushes. Confirm real progress
instead with two `docker stats` samples a few seconds apart — climbing NET
I/O and PID count means it is genuinely still working, not stuck.

**Fix, confirmed working: raise the Docker memory cap to 12 GB.** The
progression 6 GB → 10 GB → 12 GB shows this is a real, quantifiable memory
requirement, not an unfixable incompatibility — `DomXssScanRule`'s browser
launch needs somewhere between 10 GB and 12 GB of headroom on this
codebase's `/orders/track` page. If a future target needs more than 12 GB
and the host can't spare it, the remaining options (untried so far) are an
Automation Framework plan or `-c` config file excluding just
`DomXssScanRule` (the `-I` flag does *not* do this — it only suppresses
returning a failure exit code on warnings, confirmed via
`zap-full-scan.py --help`), or the baseline scan (`zap-baseline.py`) as a
fallback that proves nothing about DOM XSS specifically but finishes in
about a minute.

**Why it recurs.** `DomXssScanRule`'s browser dependency is not obvious
from the rule name or from `pentest-the-system.md`'s general cost table
(written before this was isolated) — a future memory-exhaustion failure on
a *different* target will look identical (climbing memory, no report) and
invites the same wrong guesses (concurrency flags, ambient pressure) this
entry itself went through twice. Check the log for `DomXssScanRule` /
`browser launch` specifically before assuming a new cause.

**Prevention.** Before concluding a ZAP full-scan memory failure is a new,
unexplained problem, check `docker logs` (or, if buffered and still
running, run detached and inspect after it exits) for where in the rule
sequence it actually died — `DomXssScanRule` starting is the known
reproducible trigger on this project as of 2026-09-06, confirmed by
`docker inspect`'s `OOMKilled` field, not by inference from symptoms alone.

## `ThreeDSecureTest` passes once, then fails every later run with a card form that never appears

**Symptom.** The first run of
`docker compose run --rm playwright ./vendor/bin/pest -c phpunit.browser.xml
tests/Browser/ThreeDSecureTest.php` passes. Every run after it fails with:

```
Stripe never swapped its 2px placeholder for the real card form.
```

Instrumenting the mount shows one iframe of height `2` on the first poll and
then **zero iframes** for the rest of the run — the Payment Element appears
and is then torn back out of the DOM. `#stripe-payment-element` and its
`wire:key` wrapper both survive throughout, so this looks like a Livewire
re-render wiping Stripe's mount, or a wait that is too short, or CSP
blocking the frame. It is none of those.

**Cause.** Stripe's idempotency key collides with a truncated test database.
`CreateStripeIntent` keys `paymentIntents->create` on
`'payment-intent-'.$payment->id`, deliberately — so a retry after a timeout
returns the first intent instead of charging the customer twice. Correct in
production, where ids never repeat. But the browser suite truncates every
table before each test (`tests/Pest.php`'s `->in('Browser')` block), so
`payments.id` restarts at `1` on every run. The second run therefore sends
idempotency key `payment-intent-1` again, and Stripe faithfully replays the
intent the *first* run already drove to `succeeded`. Elements refuses to
initialise against a terminal intent and removes its own iframe.

The real message is never printed by the test — it is delivered to the
Element's `loaderror` handler:

> This PaymentIntent is in a terminal state and cannot be used to initialize
> Elements. Avoid rendering Elements in this state, or create a new
> PaymentIntent if intending to collect new payment details.

**Fix.** `resetStripeIdempotencyScope()` in the test moves `payments`
AUTO_INCREMENT past every id the database has already issued, so each run
produces an idempotency key Stripe has never seen. It seeds from the clock:
truncation resets AUTO_INCREMENT, so seeding from the table's own rows would
not work — the offset must come from something monotonic and independent of
the database.

**Why it recurs.** The failure surfaces three layers away from its cause.
Nothing in the message mentions Stripe, intents, or idempotency; the visible
fact is a missing card field, which points at the wait, the selector, or
CSP — and all three are plausible here, because the Element genuinely does
mount a 2px placeholder before the real form, the iframe naming genuinely is
undocumented, and the blade genuinely does depend on `frame-src` (SEC-009).
The state also lives at Stripe, not locally, so `migrate:fresh` and dropping
the database change nothing. Worst of all, the **first run passes**, so the
test looks correct when written and only breaks for the next person.

**Prevention.** When a browser test that touches a real external API passes
once and then fails, suspect state held at the *provider*, keyed on
something the test resets, before suspecting the test. For anything driving
Stripe Elements, read the `loaderror` payload first — mount a console
collector and serialise the error object (`JSON.stringify`, not
`String(e)`, which yields a useless `[object Object]`). And never accept a
single green run of this test as proof: run it at least twice.
