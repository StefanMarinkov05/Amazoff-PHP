<p align="center">
  <img src="src/public/images/logo.png" alt="Amazoff logo" width="160">
</p>

<h1 align="center">Amazoff</h1>

Laravel e-commerce platform with a blog/news module, Stripe and cash-on-delivery
payments, and Econt/Speedy courier integrations.

Deployed on Railway, provisioned and managed through Railway's MCP server —
web, worker, and scheduler services on a Railpack build, MySQL, and a
second, dedicated media storage volume separate from the seeded-image disk
so admin uploads survive a redeploy.

The application lives in [`src/`](src/) — not at repo
root. Docker config is at the repo root ([`docker-compose.yml`](docker-compose.yml),
[`docker/`](docker/)); architecture rules and scope decisions are in
[`CLAUDE.md`](CLAUDE.md); every other doc — how-to guides,
reference facts, explanations of how the system fits together, and the
ADRs behind each architectural choice — is mapped from
[`docs/README.md`](docs/README.md), organized by
[Diátaxis](https://diataxis.fr/).

## Features

### Storefront

- Catalogue with search, category and brand facets with live counts, stock
  and sale filters, sorting, and pagination
- Product detail page — variation picker, image gallery with thumbnails and
  arrows, specifications, reviews, breadcrumb, add to basket
- Basket with line quantity editing (server-side refusal handling), coupon
  apply/remove, discount and VAT display
- Guest and registered checkout through server-recalculated totals
- Public order tracking and order history, both ownership-scoped
- Personal account management panel
- Journal/blog — article index and reading page
- About page, contact form, and footer newsletter signup
- Design system: consistent palette, radii, and animation set, with
  `prefers-reduced-motion` honoured

### Payments

- Stripe integration end to end — PaymentIntents, Elements, webhook,
  refunds
- Cash-on-delivery as a parallel payment path
- Signature-verified, CSRF-excluded webhook handling with idempotency
- 3-D Secure support, verified against the real Stripe test API
- Dispute handling and signing-secret rotation

### Courier integrations

- Econt and Speedy, abstracted behind a shared courier gateway interface

### Admin panel (Filament)

- Roles/permissions screen, with policies for every resource
- Product resource, including variation gallery and image logic
- Coupon resource with reactive form behaviour
- Order resource with a status-transition Action driving a
  status-change menu generated from the transition matrix
- Article resource with full CRUD and moderated review resource
- Inventory, Shipment, User, and Payment resources, including a refund
  surface
- Contact and newsletter resources
- Statistical widgets and a minimalist visual layout

### Architecture and data

- Business logic centralized in single-purpose Action classes
- Backed enums with casts and status-transition matrices
- Database-level constraints across pivot and catalogue tables
- Pricing resolved through a shared value object, avoiding duplicated
  discount-window logic between storefront and admin
- Demo/system/stress seeders, including a data parser and fixture
  validation commands

### Security

- Closed guest/webhook null-actor authorization bypass
- Own-permission escalation fix for role management
- Rate limiting on every public-facing form
- Closed a secret-in-URL leak on the payment return path
- Header-misconfiguration fix blocking card payments

### Testing and tooling

- Factory coverage against real schema constraints
- Action and concurrency coverage, with a dedicated race-condition harness
- Mutation testing and code-coverage tooling
- Adversarial Stripe-webhook test suite
- Performance, chaos/failure-injection, and accessibility testing
- CI pipeline with parallelized test execution

## Requirements

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (WSL2
  backend on Windows)

Nothing else needs to be installed on the host — PHP, Composer, Node, and
MySQL all run inside containers.

## Setup

```bash
git clone https://github.com/StefanMarinkov05/Amazoff-PHP.git
cd Amazoff-PHP

cp src/.env.example src/.env
# fill in Stripe/Econt/Speedy test credentials

docker compose up -d --build

docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan storage:link
docker compose exec app php artisan filament:assets
```

The last line publishes Filament's compiled CSS/JS/fonts into `public/`.
Composer's `post-install-cmd` runs `filament:upgrade`, which is meant to do
this automatically — but if it fires before the panel is fully installed, or
`public/` was created by an earlier partial install, you're left with empty
`public/css/filament` and `public/fonts/filament` directories and an unstyled
`/admin` that 404s every asset request. Running the command directly is
idempotent, so it's safe to include in setup unconditionally rather than
diagnosing it after the fact.

The app is then available at:

| Service | URL |
|---|---|
| App | http://localhost:8080 |
| Filament admin | http://localhost:8080/admin |
| Vite dev server (HMR) | http://localhost:5173 |
| Mailpit (caught outbound mail) | http://localhost:8025 |
| MySQL | `localhost:3306` |

## Default accounts

`migrate --seed` (or `docker compose exec app php artisan migrate:fresh --seed`)
creates four accounts, password `password` for all of them:

| Email | Role | Panel access |
|---|---|---|
| `admin@example.com` | administrator | yes — full access |
| `editor@example.com` | content editor | yes — articles, categories, tags |
| `warehouse@example.com` | warehouse employee | yes — orders, shipments, inventory |
| `customer@example.com` | *(none — a plain customer)* | no |

None of this is a secret: the seeder that creates them refuses to run in
production. Full route map, exact permission counts, and what each admin
resource supports is in
[`docs/reference/local-access.md`](docs/reference/local-access.md).

## Everyday commands

Run everything through `docker compose exec app`, or open a shell:

```bash
docker compose exec app bash

php artisan migrate:fresh --seed                        # reset + reseed, offline and deterministic
./vendor/bin/pint --test                                 # formatting
./vendor/bin/phpstan analyse --memory-limit=1G            # static analysis — the flag is required, not optional
./vendor/bin/pest                                         # full suite
```

`--memory-limit=1G` is required — the container's default 128M crashes
Larastan's parallel workers and reports a fake `Found 1 error`
(`docs/how-to/troubleshooting/ide-and-static-analysis.md` has the symptom).
Running a single file, filtering by test name, parallel flags, and everything
else worth knowing about the suite is in
[`docs/how-to/run-the-tests.md`](docs/how-to/run-the-tests.md).

**Demo data commands** beyond the base seeder — `demo:seed` (the full
seeder chain), `demo:fetch-images`/`demo:fetch-article-images` (pull demo
product/article imagery), `demo:stripe-payments` (open real Stripe test
intents against seeded orders), `fixtures:validate`/`fixtures:validate-articles`
(check a fixture document before it's loaded) — are documented in
[`docs/reference/console-commands.md`](docs/reference/console-commands.md)
and [`docs/how-to/seed-the-database.md`](docs/how-to/seed-the-database.md).

Stop the stack with `docker compose down`; add `-v` to also drop the
database volume.

## Stripe webhooks locally

```bash
stripe listen --forward-to localhost:8080/stripe/webhook
```

Copy the printed webhook signing secret into `src/.env` as
`STRIPE_WEBHOOK_SECRET` — this value is per-machine, never shared or
committed. Full setup, including the two-Stripe-account trap that has
bitten this project before, is in
[`docs/how-to/set-up-stripe.md`](docs/how-to/set-up-stripe.md).

## Finding your way around

- **Application code** — [`src/app/`](src/app/), one
  Action per business write (`app/Actions/{Area}/{Verb}{Noun}.php`),
  Livewire components for the storefront, Filament resources for
  `/admin`. `CLAUDE.md` and
  [`docs/reference/coding-conventions.md`](docs/reference/coding-conventions.md)
  state the non-negotiable rules; don't infer architecture from one file
  you happen to be looking at.
- **Docker setup** — [`docker-compose.yml`](docker-compose.yml) at the repo
  root, per-service config under [`docker/`](docker/).
- **Documentation** — [`docs/`](docs/), Diátaxis-organized; start at
  [`docs/README.md`](docs/README.md) if you're not sure which section has
  what you need. `docs/reference/diagrams/` has visual state/sequence/
  deployment diagrams if a picture would help more than the prose.

## CI

See [`CONTRIBUTING.md`](CONTRIBUTING.md#ci) for what runs, when, and why a
red check doesn't currently block a merge.

## License

[MIT](LICENSE)
