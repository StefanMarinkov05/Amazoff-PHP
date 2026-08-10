# Tech stack, at a glance

What this is built with, and why, in short. Full reasoning is in ADR-0001.
This page describes what's actually in the codebase now — for the parts
that are decisions rather than built code, ADR-0001 has the plan.

## Built and running

Laravel 13 on PHP 8.4, in Docker — `app`, `webserver`, `db`, `vite`,
`mailpit`. Verified running end to end: welcome page and Filament's
`/admin/login` both serve over the full nginx → PHP-FPM → MySQL chain.

Filament is installed and its panel provider registered.
`canAccessPanel()` on `User` gates it by role. No Filament Resources exist
yet — the admin panel has no content in it.

Roles come from `spatie/laravel-permission`. `User` uses the `HasRoles`
trait; `canAccessPanel()` checks `hasAnyRole(User::STAFF_ROLES)`. Verified
working — staff reach the panel, a customer does not, and a user can hold
two roles at once.

The three staff role rows (`administrator`, `content_editor`,
`warehouse_employee`) exist in the local database but **there is no seeder
for them**, so a fresh `migrate:fresh --seed` produces none. No permissions
are defined either — only roles.

No Policy classes exist yet, so `canAccessPanel()` is currently the only
authorization check in the codebase. "All checks go through Policies" is the
target from ADR-0001, not the current state.

Blueprint has generated the schema from `online-store/draft.yaml`: 40
migrations, 32 models, 32 factories. `migrate:fresh` applies cleanly and
every factory persists a row. The models are data structures only — no
business logic, no Policies, no Actions.

Larastan and Pest are configured and passing against what exists so far.

## Installed, not wired in

Saloon, Stripe's SDK, Purify, `astrotomic/laravel-translatable`, and
`spatie/laravel-activitylog` are all in `composer.json`. None of them have
any application code using them yet — no `CourierGateway`, no Livewire
components, no payment or shipment logic beyond the generated models.
ADR-0001 has the reasoning for each; this is a statement that the reasoning
hasn't been tested against real code yet.

## Open

Content translation storage shape and audit log shape are undecided. PHP
version (`^8.3` declared, `8.4` actually required by the lockfile) is
unresolved.
