# Tech stack

Everything currently in `online-store/composer.json` and the dev
environment. No rationale here — that's in
`docs/explanation/tech-stack-overview.md` and
`docs/adr/0001-tech-stack-selection.md`.

## Runtime

| Package | Purpose |
|---|---|
| `laravel/framework` ^13.8 | Application framework |
| `filament/filament` ^4.0 | Admin panel |
| `livewire/livewire` (via Filament) | Storefront interactivity |
| `spatie/laravel-permission` ^8.3 | Roles and permissions |
| `saloonphp/saloon` ^4.0 | Econt/Speedy API clients |
| `stripe/stripe-php` ^21.1 | Stripe SDK |
| `stevebauman/purify` ^6.3 | HTML sanitization for article/review content |
| `astrotomic/laravel-translatable` ^11.17 | Content translation tables — pending |
| `spatie/laravel-activitylog` ^5.0 | Audit log — pending |

## Dev / tooling

| Package | Purpose |
|---|---|
| `larastan/larastan` ^3.10 | Static analysis (PHPStan + Laravel-aware rules) |
| `pestphp/pest` ^4.7 | Test framework |
| `pestphp/pest-plugin-laravel` ^4.1 | Laravel test helpers for Pest |
| `laravel-lang/common` ^6.8 | Framework translation strings, non-English locales |
| `laravel-shift/blueprint` ^2.13 | Migration/model/factory scaffolding from `draft.yaml` |
| `laravel/pint` ^1.27 | Code formatting |
| `laravel/pao` ^1.0.6 | Compresses PHPUnit/Pest/PHPStan/Artisan output when run inside an AI agent |

## Infrastructure

| Tool | Purpose |
|---|---|
| Docker Compose | Local dev: `app` (PHP-FPM), `webserver` (nginx), `db` (MySQL 8), `vite`, `mailpit` |
| GitHub Actions | CI — Pint, Larastan, Pest against a real MySQL service container |
| Forge + VPS | Production, not containerized |

## Currently unresolved

- **PHP 8.3 vs 8.4** — `composer.json` declares `^8.3`; the lockfile is
  solved against packages requiring 8.4. Docker and CI both currently follow
  8.4 to match the lockfile.
- **`astrotomic/laravel-translatable`** — installed. Translation storage
  shape (tables vs JSON columns) not settled. `lang/` and one migration set
  aside.
- **`spatie/laravel-activitylog`** — installed. Audit log shape not
  settled. Migration set aside.
