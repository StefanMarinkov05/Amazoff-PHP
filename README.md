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

## Everyday commands

Run everything through `docker compose exec app`, or open a shell:

```bash
docker compose exec app bash

php artisan migrate:fresh --seed   # offline, deterministic

./vendor/bin/pint --test
./vendor/bin/phpstan analyse
./vendor/bin/pest
```

Running a single file, filtering by test name, and the flags worth knowing are
in [`docs/how-to/run-the-tests.md`](docs/how-to/run-the-tests.md).

`fixtures:validate`, `demo:simulate`, and `demo:race` are planned per
[`docs/adr/0003-seeding-data.md`](docs/adr/0003-seeding-data.md) and do not
exist yet.

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
and Pest against MySQL on every push to `main` and every pull request,
regardless of target branch. External APIs are mocked, so it needs no real
credentials. A green check does not block a merge on this repository — see
[`docs/how-to/use-ci.md`](docs/how-to/use-ci.md).
