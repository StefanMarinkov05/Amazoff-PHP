# Changelog

Format follows [Keep a Changelog](https://keepachangelog.com/). Dates are
when the work happened, not when it was committed — nothing in
`Unreleased` has a git commit yet.

## Unreleased

### Added

- Docker Compose local dev environment: `app` (PHP-FPM), `webserver`
  (nginx), `db` (MySQL 8), `vite`, `mailpit`.
- Filament admin panel, installed and verified against Laravel 13.8.
- `spatie/laravel-permission` for roles. `User` uses `HasRoles`;
  `canAccessPanel()` gates the Filament panel on `User::STAFF_ROLES`.
  Verified: staff reach the panel, customers do not, multi-role works.
- `spatie/laravel-activitylog` and `astrotomic/laravel-translatable`
  installed — not wired in yet, decisions behind them still open.
- Saloon, Stripe SDK, Purify, Larastan, Pest, `laravel-lang/common` added
  to `composer.json`.
- GitHub Actions CI workflow: Pint, Larastan, Pest against a real MySQL
  service container, triggered on push/PR to `main`.
- `docs/` restructured around Diátaxis, plus `adr/` and `changelog/`.
- `docs/adr/0001-tech-stack-selection.md` — combined ADR for kickoff-phase
  tech choices.
- `docs/explanation/documentation-design.md` — how `docs/` is organized and
  the ADR vs explanation split.
- `docs/explanation/gdpr.md` — soft vs hard delete, order anonymization,
  hashed coupon redemption identifiers.
- `docs/how-to/regenerate-with-blueprint.md` — safe regeneration procedure,
  the two hand-written files, and the generated code that needs correcting.
- `docs/how-to/use-ci.md` — what the workflow runs, how to reproduce a
  failure locally, and what a green check does not cover.
- `CLAUDE.md`, `CONTRIBUTING.md`, `CONTRIBUTIONS.md`,
  `.github/PULL_REQUEST_TEMPLATE.md` at repo root.
- Schema generated from `draft.yaml`: 39 migrations, 32 models, 32
  factories. `migrate:fresh` applies cleanly; every factory persists a row.
- `online-store/stubs/blueprint/` — overrides `model.fillable.stub` and
  `model.hidden.stub` to emit `@var list<string>`, which Larastan requires.

### Fixed

- `app/Models/User.php` was invalid PHP — an unclosed `$hidden` array and an
  unclosed `profile()` method from a merge conflict resolved by hand. It also
  referenced `Profile`, `Role`, and `Permission`, all removed in the
  `spatie/laravel-permission` switch, and redefined `roles()`, colliding with
  the `HasRoles` trait. Now hand-written and excluded from generation.
- Four factories contained empty class names (`use App\Models\;`,
  `::factory()`) and would not parse.
- 21 factories referenced columns that no longer existed after the schema
  revision. Blueprint does not overwrite existing files, so a second
  `blueprint:build` had layered new columns onto stale ones.
- `AddressFactory` and `OrderAddressFactory` generated `fake()->country()`
  into a `char(2)` column, failing with a truncation error on insert.
- `ProductCategoryFactory` set `'parent_id' => ProductCategory::factory()`
  on a self-referencing key, recursing without termination.
- `UserFactory` hashed a random password per row and left no known password
  for tests to log in with. Now hashes once per process, with an
  `unverified()` state.

### Removed

- `App\Enums\Role`, `App\Models\UserRoleAssignment`, and the `user_roles`
  migration. The enum + pivot approach was built first, then replaced by
  `spatie/laravel-permission` — §3.5 requires runtime-editable permissions.

### Open

- PHP version: `composer.json` declares `^8.3`, the lockfile requires 8.4.
  Not pinned.
- Content translation storage shape and default locale.
- Audit log shape.
- No seeder for the three staff roles. They exist in the local database but
  a fresh `migrate:fresh --seed` creates none.
