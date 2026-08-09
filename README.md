# Online Shop — Team B

Laravel e-commerce platform with a blog/news module, Stripe and cash-on-delivery
payments, and Econt/Speedy courier integrations. Internship project, Lumen101
2026.

The application lives in [`online-store/`](online-store/). Architecture rules
and scope decisions are in [`CLAUDE.md`](CLAUDE.md); contributing guidelines
are in [`CONTRIBUTING.md`](CONTRIBUTING.md); documentation and decisions are
mapped from [`docs/README.md`](docs/README.md).

## Requirements

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (WSL2
  backend on Windows)

Nothing else needs to be installed on the host — PHP, Composer, Node, and
MySQL all run inside containers.

## Setup

```bash
git clone https://github.com/Lumen101-Internship-2026/Online_Shop_TeamB.git
cd Online_Shop_TeamB

cp online-store/.env.example online-store/.env
# fill in Stripe/Econt/Speedy test credentials from the team vault

docker compose up -d --build

docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan storage:link
```

The app is then available at:

| Service | URL |
|---|---|
| App | http://localhost:8080 |
| Filament admin | http://localhost:8080/admin |
| Vite dev server (HMR) | http://localhost:5173 |
| Mailpit (caught outbound mail) | http://localhost:8025 |
| MySQL | `localhost:3306` |

## Everyday commands

Run everything through `docker compose exec app`, or open a shell:

```bash
docker compose exec app bash

php artisan migrate:fresh --seed   # offline, deterministic
php artisan fixtures:validate      # gate on scraped/translated data
php artisan demo:simulate          # demo data — NOT part of the merge gate
php artisan demo:race              # concurrency check on stock reservation

./vendor/bin/pint --test
./vendor/bin/phpstan analyse
./vendor/bin/pest
```

Stop the stack with `docker compose down`; add `-v` to also drop the database
volume.

## Stripe webhooks locally

```bash
stripe listen --forward-to localhost:8080/stripe/webhook
```

Copy the printed webhook signing secret into `online-store/.env` as
`STRIPE_WEBHOOK_SECRET` — this value is per-machine, never shared or committed.

## CI

[`.github/workflows/ci.yml`](.github/workflows/ci.yml) runs Pint, Larastan,
and Pest against MySQL on every push and pull request targeting `main`. It is
the merge gate (ADR-0024) — external APIs are mocked, so it needs no real
credentials.
