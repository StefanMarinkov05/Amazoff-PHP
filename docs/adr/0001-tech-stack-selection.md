# ADR-0001: Tech stack selection

Status: Proposed
Date: 2026-08-08 · Deciders: team

## Context

§1 requires PHP and Laravel. The framework was not a choice. Everything
else below was.

## Decision

### Laravel 13, PHP 8.4

`composer.json` declares PHP `^8.3`. `composer.lock` requires 8.4 —
`symfony/*` packages in it need PHP >=8.4.1. Docker and CI are pinned to 8.4
to match the lockfile. Which version is correct is not decided.

### Filament, admin panel

Filament 4.12 was tested directly against Laravel 13.8 and resolves without
conflict. Installed, panel provider registered. No resources built yet.

### Livewire + Alpine, storefront

§33 does not ask for an API. A separate JS frontend would need Sanctum,
duplicate validation on both sides, and two build pipelines for two
developers. Livewire keeps logic in PHP, testable with Pest without a
browser. Alpine handles client-side interactions — quantity steppers, filter
toggles — that would otherwise round-trip to the server on every input.

### Blueprint, scaffolding

Installed, not used yet — no `draft.yaml` exists in this repo. Intended use:
generate migrations, models, and factories per vertical slice from
`draft.yaml`. Not a round-trip tool — once a slice's models are hand-edited,
that slice is not regenerated. §27 lists around 25 entities; the boilerplate
part of each is repetitive to hand-write.

### Saloon, courier clients

Installed, not used yet — no `CourierGateway` interface or courier
connector exists in this repo. §37 requires Econt and Speedy behind one
shared interface. §36 requires external APIs to be mockable in tests.
Intended use: Saloon for structured requests and built-in mocking per
courier, behind a `CourierGateway` interface both implement. Stripe uses
its own SDK directly and is not part of this decision.

### Purify, content sanitization

`stevebauman/purify`, wraps HTMLPurifier. Installed, not wired in — no
article or review model exists yet to sanitize. Intended use: sanitize
article and review content before storage. §22–24 require rich-text content
with headings, lists, links, embedded video — plain `strip_tags` would
remove that formatting along with the risk.

### spatie/laravel-permission, roles

§3 roles are not hierarchical — Content Editor and Warehouse Employee are
each denied data the other can see. Guest and Registered Customer are not
roles in this sense: Guest has no user row, Customer is the default
authenticated state. Three assignable staff roles remain: `administrator`,
`content_editor`, `warehouse_employee`.

§3.5 lists "Roles and permissions" as an administrator section, so
permissions have to be editable without a deploy. The package provides that,
and has a Filament plugin for the admin surface.

`User` uses the `HasRoles` trait. `canAccessPanel()` checks
`hasAnyRole(User::STAFF_ROLES)`. Beyond panel access, authorization goes
through Policies; no code outside a Policy checks a role name directly.

Installed and verified: role assignment, multi-role users, and panel gating
for staff vs customer all work against the running app.

### Larastan, static analysis

PHPStan alone does not understand Eloquent's dynamic properties, relations,
and query builder returns. Larastan adds the Laravel-aware rules on top.

### Pest, tests

Functional syntax in place of PHPUnit's default class syntax. Runs on
PHPUnit underneath.

### laravel-lang/common

Framework strings (validation, auth messages) for locales other than
English. Not currently wired — the app locale is English; the Bulgarian
language files generated earlier were set aside.

### laravel/pao

Dev dependency. Detects PHPUnit/Pest/PHPStan/Artisan running inside an AI
coding agent and compresses their output.

### astrotomic/laravel-translatable, spatie/laravel-activitylog

Both installed. The decisions behind them — content translation storage
shape, audit log shape — are not made. Supporting files (`lang/`, one
migration) were set aside.

### Docker, local dev

Both developers, from project start. Same PHP, MySQL, and Node versions on
both machines and in CI. Production runs on Forge, no containers.

## Consequences

+ Same environment on both machines and in CI. Verified: Docker stack built
  and run end to end, welcome page and `/admin/login` both serving.

− PHP version not formally decided, only inherited from the lockfile.
− Docker adds daily overhead. Accepted because both developers already use
  it.
− Two packages installed without a settled design behind them
  (`astrotomic/laravel-translatable`, `spatie/laravel-activitylog`).
− Filament, Blueprint, Saloon, Purify, and Livewire are installed but not
  yet used anywhere in `app/`. Whether they deliver on the reasoning above
  is not yet tested against real code.

## Alternatives rejected

- Hand-built admin CRUD. Filament covers it faster.
- React/Vue SPA. Doubles the surface area for two developers; requires an
  API layer §37 does not ask for.
- Hand-written migrations, models, and factories for every entity.
  Blueprint covers the repetitive parts.
- Guzzle / `Http::` facade directly for couriers. No structured place for
  per-courier auth and error handling.
- A dedicated package per courier. Neither Econt nor Speedy has one.
- Backed enum plus a `user_roles` pivot, no package. Fewer tables, but no
  runtime-editable permissions, which §3.5 asks for. This was built first
  and then removed — the enum, its pivot model, and their migration are
  gone from the codebase.
- Single `role` column on `users`. Cannot hold two roles without inventing
  combined values.
- Laravel Sail. Hand-written Dockerfile instead — more transparent, and
  Sail's volume defaults are worse on Windows.
- Native install, no containers. Usual default for two developers. Not used
  here since both already run Docker.

## Open questions

- PHP 8.3 or 8.4. Not pinned in `composer.json`'s `config.platform`.
- Content translation storage shape — translation tables vs JSON columns.
- Audit log shape — package vs hand-rolled.
