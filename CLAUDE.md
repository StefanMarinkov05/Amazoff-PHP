# Online Shop — Team B

Laravel e-commerce platform with a blog/news module, Stripe and
cash-on-delivery payments, and Econt/Speedy courier integrations.
Internship project, Lumen101 2026.

The app lives in [`online-store/`](online-store/), not at repo root. Local
dev runs through Docker Compose — see [`README.md`](README.md) for setup.

**Read [`docs/README.md`](docs/README.md) before touching anything
architectural.** It maps to [`docs/adr/`](docs/adr/) (decisions, one
combined file for now, see its note on granularity) and
[`docs/explanation/`](docs/explanation/) (how the system currently fits
together). If a suggestion contradicts an accepted ADR, say so explicitly
rather than silently diverging.

---

## Architecture — non-negotiable

- Business logic lives in `app/Actions/*`. One command per class, single
  `handle()`.
- Controllers and Livewire components are thin: validate → call Action →
  respond.
- Filament resources call the same Actions as the storefront. Never Eloquent
  directly — this is what keeps two developers from building two subtly
  different versions of the same business rule.
- No repository pattern. Eloquent is the repository.
- Every fixed value set is a backed enum in `App\Enums`, with behaviour on
  it (`->label()`, `->color()`, `->canTransitionTo()`). Never the same list
  twice.

  Exception: roles. Provided by `spatie/laravel-permission`, not an enum —
  §3.5 requires them editable at runtime. See
  `docs/adr/0001-tech-stack-selection.md`.
- Money: `decimal(10,2)` columns, `decimal:2` casts, `bcmath` arithmetic.
  Never float.
- Contested state (stock, coupon usage): `DB::transaction` **and**
  `lockForUpdate()`. The transaction alone does not prevent the race.
- Order status changes go through `TransitionOrderStatus`. Never assign
  `->status` directly — it bypasses history, events, and role gates.
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

## Scope

- **VAT in scope.** Prices stored gross (BG B2C convention). Per-product
  `vat_rate`, snapshotted onto order items.
- **Cash on delivery in scope.** COD orders skip Stripe, reserve stock on
  confirmation, carry the COD amount to the courier, and are marked paid on
  remittance.


## Working style

- Vertical slices, atomically. One entity fully (migration → model →
  factory → policy → admin resource → public UI) before starting a
  dependent one.
- Short-lived branches: `feat/<name>/<scope>`, merged within roughly three
  days. Rebase on `main` daily.
- Migrations are append-only after the schema freeze. Never edit a merged
  migration; always add a new one.
- Generated code is a first draft. It gets read before it's trusted.
- Verify against a running app. `php -l` proves syntax, not behaviour.

## Commands

Run through Docker — see `README.md` for the full setup and everyday
command list. Short version:

```bash
docker compose exec app php artisan migrate:fresh --seed
docker compose exec app ./vendor/bin/pint --test
docker compose exec app ./vendor/bin/phpstan analyse
docker compose exec app ./vendor/bin/pest
```
