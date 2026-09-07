# Coding conventions — architecture and security rules

The single source for this project's non-negotiable architecture and
security rules. `CLAUDE.md` at the repo root and
`online-store/.ai/guidelines/project-conventions.md` (compiled into the
Boost-generated `online-store/CLAUDE.md`) both point here rather than
restating this content — if you're editing a rule, edit it **here**, then
update both pointers' short summaries only if the one-line description
itself changed.

These rules apply on every coding task in this repo — writing an Action,
touching a Filament resource, adding a migration, anything that isn't pure
documentation work. `explanation/`, `adr/`, and `reference/write-rules/`
carry the *why*; this page is the *what*, kept short enough to scan before
writing a line of code.

## Architecture — non-negotiable

- Business logic lives in `app/Actions/{Area}/{Verb}{Noun}.php`. One command
  per class, single `handle()`, grouped by the aggregate the write belongs
  to, not by the caller. `docs/reference/actions.md` is the current,
  complete list; don't infer what exists from memory or from this file.
- Controllers and Livewire components are thin **on the write path**:
  validate → call Action → respond. Reads are the component's own business —
  a storefront page queries Eloquent directly rather than through an Action,
  because a read has no invariant for an Action to own. See ADR-0014, and
  `docs/explanation/storefront-pages.md` for the shape a page takes.
- Filament resources call the same Actions as the storefront wherever a rule
  exists — this is what keeps two developers from building two subtly
  different versions of the same business rule. A rule exists when a write
  spans more than 1 table or enforces an invariant the schema cannot
  express: a product needs a variation and an inventory row, an order needs
  items and addresses, a status change needs a history row. Plain lookup
  tables (`Brand`, `Tag`, `Attribute`, `AttributeValue`, `ProductCategory`,
  `ArticleCategory`, `Carrier`) keep Filament's default CRUD, because
  wrapping a single-table save in an Action buys nothing and costs a class.
  So does `CouponResource` — coupon *redemption* is the contested state,
  not the coupon row itself. See ADR-0007.
- No repository pattern. Eloquent is the repository.
- Every fixed value set is a backed enum in `App\Enums`, with behaviour on
  it. Never the same list twice. Display goes through Filament's `HasLabel`
  and `HasColor` contracts — Filament reads those off the enum by itself, so
  a bespoke `label()` would have to be wired up in every resource showing the
  column. Lifecycle behaviour (`canTransitionTo()`) only where illegal moves
  exist and some actor can attempt them; see `docs/adr/0004-state-transitions.md`.

  Exception: roles. Provided by `spatie/laravel-permission`, not an enum —
  §3.5 requires them editable at runtime. See
  `docs/adr/0001-tech-stack-selection.md`.
- Money: `decimal(10,2)` columns, `decimal:2` casts. Arithmetic goes through
  `App\Support\Money`, never raw `bc*` calls or float. See
  `docs/explanation/money.md`.
- **A table column crossing a relation gets that relation eager-loaded**, via
  `->modifyQueryUsing(fn ($q) => $q->with([...]))`. Filament does no
  eager-loading of its own — a `make('brand.name')` column is one extra query
  per row, and the page still renders, so it goes unnoticed. Applies equally
  to an accessor that reads a relation (`Order::$payment_status`), where the
  column name contains no dot to hint at it. `preventLazyLoading()` is
  deliberately still off (ADR-0012); the rule is enforced by review and by
  query-count tests. `docs/explanation/filament-resources.md`, "Eager
  loading, and the N+1 rule".
- Contested state (stock reservation, coupon usage caps): `DB::transaction`
  **and** `lockForUpdate()` on the row the invariant actually lives on —
  not necessarily the row being written. The transaction alone does not
  prevent the race; `docs/reference/write-rules/concurrency.md` has the
  full contested-resource map and lock order.
- Order status changes go through the `TransitionOrderStatus` Action —
  designed in ADR-0004, routed by `OrderPolicy::updateStatus()` per
  ADR-0011, and built. It is the **only** writer of `orders.status` and of
  `order_status_histories`; never assign `->status` directly, and never
  compose the inventory effect yourself — the Action already picks
  `ReleaseStock`/`CompleteSale`/`RestockReturn` by target status.
  `CreateOrder` still lands every order at `New` regardless of payment
  method; moving it from there is a separate, deliberate call.
- External APIs sit behind a Saloon connector plus an interface in
  `App\Contracts`. Abstract the courier (two implementations); do not
  abstract Stripe (one).
- Validation via Form Requests. Never `$request->all()`.
- Authorization on every mutating path and every route taking an ID. A
  hidden button is not security.

## Security rules that are ours, not the framework's

- Scope queries to the user (`auth()->user()->orders()->findOrFail($id)`),
  never `Order::findOrFail($id)`.
- `canAccessPanel()` on the User model checks role membership — Filament is
  not protected past login by default.
- Stripe webhook: CSRF-excluded **and** signature-verified. One without the
  other is a free-products vulnerability.
- Article and review bodies are user input. Purify before rendering;
  `{!! !!}` escapes nothing.
- Idempotency via a UNIQUE constraint plus caught violation, never
  check-then-act.
- Public order tracking requires order number **and** email — sequential
  numbers alone enumerate every customer's address.
- Totals are always recalculated server-side. The browser total is never
  trusted.

## Testing discipline

- Run everything through Docker: `docker compose exec app ./vendor/bin/pest`.
  Larastan needs `--memory-limit=1G` — not optional; the container default
  crashes its workers and reports a fake `Found 1 error`.
- `tests/Feature` uses `LazilyRefreshDatabase`. `tests/Concurrency`
  deliberately does **not** — those tests need a second real connection to
  see committed rows, and spawn `php artisan race:worker` subprocesses.
- **Never run `tests/Concurrency` under `pest --parallel`.** Laravel only
  gives a test case its own per-worker database when it uses
  `RefreshDatabase` or a sibling trait, so parallel workers collide.
  `--parallel --processes=4 --testsuite=Feature` is the supported form.
- A test that has never been observed failing proves nothing. After writing
  one, break the thing it covers and confirm it goes red.
- **A new test file must be added to a CI shard in
  `.github/workflows/ci.yml` in the same change.** Sharding is a
  hand-maintained file list, not auto-discovery — an unlisted file runs
  nowhere in CI. `docs/how-to/use-ci.md` has the placement rule: shard 2
  by default for `tests/Unit`/`tests/Feature`, shard 1 only if it shares
  `RolePermissionTest`'s per-test triple-reseed cost; the lightest
  concurrency shard by default for `tests/Concurrency`.
- Verify against a running app, not by reading code. `php -l` proves syntax,
  Larastan proves types, Pest proves the paths it covers — none of them
  executes the behaviour. `docs/how-to/troubleshooting/`'s own cases are all
  green-static-check, wrong-behaviour bugs.
- **A test earns its place by proving something ours, not the framework's.**
  `ProductResourceTest.php`'s `'refuses a product with no variations'`
  already draws this line correctly: it exercises Filament's own
  `Livewire::test()->fillForm()->assertHasFormErrors()` machinery, but what
  it *proves* is `ProductRequiresVariationException`'s territory — a domain
  rule expressed through a form, not the form's plumbing. The same
  reasoning that keeps a plain lookup table on default Filament CRUD
  applies one layer down: a test reasserting that `->acceptedFileTypes()`
  rejects a disallowed MIME type, or that `Illuminate\Validation\Rules
  \Dimensions` rejects a too-small image, proves Filament and Laravel work,
  which their own upstream suites already do — it costs a flaky,
  fixture-heavy test for zero information gained. What *is* worth
  confirming there is that the right constant reached the right method
  (`ProductImage::MIN_WIDTH_PX` actually wired into the form) — Larastan
  already does that, by refusing to compile a typo'd or wrongly-typed
  reference. Before writing a test, name what it would prove and check
  whether that thing is ours.

## Working style

- Vertical slices, atomically. One entity fully (migration → model →
  factory → policy → admin resource → public UI) before starting a
  dependent one.
- Migrations are append-only after the schema freeze. Never edit a merged
  migration; always add a new one.
- Generated code is a first draft. It gets read before it's trusted.
- **Before opening or updating a PR, merge `main` into the branch and
  resolve any conflicts as part of that same session** — don't leave a
  conflict for the PR to surface later. Re-run the full local check
  (`pint --test`, `phpstan analyse --memory-limit=1G`, `pest`) after
  resolving, since a textually clean merge can still combine two branches
  into behaviour neither one had alone.

## Where Boost's generic guidance does not apply here

The Laravel-ecosystem guidelines Boost generates are good general advice
and mostly agree with the above. These specific points do not apply, and
this is why:

- **"Only create documentation files if explicitly requested."** Not here.
  Documentation is part of the work, not an extra: solving an error that is
  not in `docs/how-to/troubleshooting.md` means adding an entry to it;
  shipping an Action means updating `docs/reference/actions.md` and the
  relevant `write-rules/` page; anything user-visible earns a
  `docs/changelog/CHANGELOG.md` entry. What still needs asking first is a
  *new* doc file or a new ADR, because those change the structure.
- **`php artisan test --compact`.** This project runs everything through
  Docker and calls Pest directly:
  `docker compose exec app ./vendor/bin/pest`. See
  `docs/how-to/run-the-tests.md`. Larastan additionally needs
  `--memory-limit=1G`.
- **`php artisan make:*` for scaffolding.** The schema was generated by
  Blueprint from `online-store/draft.yaml`, and models, factories, and
  migrations follow that file's conventions —
  `docs/how-to/regenerate-with-blueprint.md` and
  `docs/how-to/add-an-action.md` describe how new pieces are added here.
  `make:` is fine for a one-off class; it is not the route for a model.
- **"Most tests should be feature tests."** True for `tests/Feature`, but
  this project also has `tests/Concurrency`, which exists precisely because
  a feature test in one process cannot prove a lock. See
  `docs/explanation/concurrency-and-locking.md` before writing one.
- **"Always use constructor injection, avoid `app()`/`resolve()`."** Actions
  in this codebase are resolved at the call site — `app(SomeAction::class)
  ->handle(...)` — from Filament pages, console commands, and every test.
  An Action is invoked once per call, not held as a long-lived collaborator,
  so there is nothing for a constructor to hold. This is the existing
  pattern across the whole codebase, not an exception to introduce.
- **"Code to interfaces at system boundaries... payment gateways."**
  ADR-0001 decided the opposite, explicitly and by name: abstract the
  courier (two real implementations, Econt and Speedy) behind
  `App\Contracts` plus Saloon connectors; do **not** abstract Stripe (one
  implementation, nothing to swap it for). An interface over a single
  implementation is speculative generality, not testability.
