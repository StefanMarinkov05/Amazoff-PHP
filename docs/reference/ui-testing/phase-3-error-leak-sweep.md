# Phase 3 — error-leak and information-disclosure sweep

Companion to Phase 1 (`phase-1-storefront-clickthrough.md`) and Phase 2
(`phase-2-admin-created-data.md`), a different discipline from both: not
"does this crash" or "what does bad admin data do to the customer," but
"what does an *unhandled* failure actually show" — verbose stack traces,
leaked file paths, SQL fragments, unescaped values in an HTML attribute.

**Date of record:** 2026-09-03/04, same stack. `app.debug` confirmed `true`
before this pass started — the correct local-dev default, and the setting
that determines whether any of this matters at all. Everything found here
is either invisible or a bare 500 once `APP_DEBUG=false`, which is exactly
why the finding is about the *crash underneath* the leak, not the leak in
isolation.

## What held

**The `value=""` attribute-breakout case, tested directly.**
`?search=" onmouseover="alert(1)` — a payload built to close the `value`
attribute's own quote and open a new one — was checked against the raw
response HTML, not assumed safe from reading Blade source. Zero instances
of the working, unescaped form (`onmouseover="alert(1)"`); the escaped form
(`&quot; onmouseover`) appears exactly where the search value is echoed
back. Confirms what a codebase-wide `grep` for `{!! !!}` already
suggested — Blade's `{{ }}` default escaping is the only path any user
value reaches an attribute through, everywhere — but this checked the
*rendered output*, not only the absence of the dangerous syntax in source.

**A nonexistent product slug.** `/products/does-not-exist-at-all` — plain
404, zero SQL/query/exception text in the response. Route-model binding on
`Product $product` (a typed model, not a scalar) means a bad slug never
reaches user code at all; `ModelNotFoundException` is caught by the
framework before anything this project wrote runs.

**Every other route parameter in the codebase.** Swept `routes/web.php` for
every parameter, not only the one that turned out to be broken:
`{product:slug}` and `{article:slug}` both bind typed models (structurally
immune, same reasoning as above); `{order}` was the only plain scalar
parameter in the entire route file, and it is where the one real finding
was.

## What did not hold — a debug page to an anonymous visitor

`/checkout/confirmation/abc` produced a full Laravel/Ignition debug
page — not a 404, not a validation error, an unhandled `TypeError` — to a
completely unauthenticated request.

**What it exposed**, confirmed by reading the actual response body rather
than assuming from the status code: the exception class and message, the
exact source line (`app/Livewire/Checkout/OrderConfirmation.php:60`), the
full container dependency-resolution call stack, and absolute filesystem
paths (`/var/www/html/vendor/laravel/framework/src/...`) for every frame.
None of that is *sensitive* on its own — it is this project's own open
source, not a secret — but it is exactly the reconnaissance information a
real attack normally has to work to obtain: confirmed framework version
paths, the application's internal directory layout, and proof of an
unhandled crash to build further payloads against.

**The root cause was a crash, and the leak was downstream of it.**
`OrderConfirmation::mount(int $order)` — `{order}` is a plain route
segment, not a route-model-bound `Order $order` (the component's own
`$orderId` docblock explains why: a matching property name would collide
with Livewire's hydration). Nothing validates the segment's shape before
PHP's own type coercion runs, so `/checkout/confirmation/abc` threw
`TypeError: ... must be of type int, string given` before `mount()`'s body
executed — the same "hydration happens before your code does" shape as
every `#[Url]` crash in Phase 2, on route binding instead of query-string
binding. This is the fourth *component* to have this incident, and it is
the reason `test-for-input-crashes.md`'s playbook — 34-digit overflow,
decimal, non-numeric, negative — is written per property rather than per
component: a route parameter is a property too, just bound at a different
point in the request lifecycle.

**Confirmed the severity split precisely, not assumed it.** With
`APP_DEBUG=true` (this environment's correct local setting), the leak is
real. Laravel's own exception handler — not anything this project
customised — gates that page behind `app.debug`; with it `false` (the
pre-deploy requirement `how-to/pentest-the-system.md` already states), the
same input produces a bare, contentless 500. But a bare 500 is still a
genuine defect independent of the debug question: an unhandled crash for a
customer who mistypes or hand-edits a confirmation URL, where a bad order
id already gets a clean 404.

**Fixed the same session.** Widened to `mixed`, an explicit `is_numeric`
check throws the identical `NotFoundHttpException` the component's own
`authorizedOrder()` already throws for a well-formed id nothing matches —
one 404 shape for "not an order at all" and "not your order," rather than
two different failure modes for two adjacent cases. 3 new tests in
`tests/Feature/Payment/CheckoutTest.php`, each verified red without the fix
(the exact same `TypeError`, reproduced inside the test suite). Full detail:
`reference/tested-inputs.md`'s `OrderConfirmation` section.

## Not covered by this pass

- **A systematic crawl for every unhandled-exception path**, beyond the
  route-parameter sweep above. This pass checked every *route parameter*
  specifically, because that is where the one confirmed instance was and
  where the crash class established in Phase 2 predicted the next one would
  be. Form-submission paths, Filament resource actions, and console
  commands were not swept the same way.
- **Log-file disclosure** — whether `storage/logs/laravel.log` or any debug
  artifact is reachable over HTTP. Not attempted; `docker/nginx/default.conf`
  serves only `public/`, which does not contain the log directory, so this
  is expected to be closed by the directory structure itself rather than
  independently verified.
- **Header-based information disclosure** beyond what `security-tooling.md`
  already covers (`Server`, `X-Powered-By`, both closed in that pass).
- **Authenticated-session error paths** — every case here was run as an
  anonymous guest. An authenticated request hitting the same class of bug
  was not separately checked, though nothing in `OrderConfirmation`'s fix
  is auth-conditional.
