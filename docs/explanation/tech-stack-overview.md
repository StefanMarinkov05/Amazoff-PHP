# Tech stack, at a glance

What this is built with, and why, in short. Full reasoning is in ADR-0001.
This page describes what's actually in the codebase now — for the parts
that are decisions rather than built code, ADR-0001 has the plan.

## Built and running

Laravel 13 on PHP 8.4, in Docker — `app`, `webserver`, `db`, `vite`,
`mailpit`. Verified running end to end: welcome page and Filament's
`/admin/login` both serve over the full nginx → PHP-FPM → MySQL chain.

Filament is installed and its panel provider registered.
`canAccessPanel()` on `User` gates it by role. Seven Resources exist over the
catalogue's lookup entities — `Brand`, `Tag`, `ProductCategory`,
`ArticleCategory`, `Attribute`, `AttributeValue`, `Carrier` — scaffolded with
`make:filament-resource --generate` and corrected by hand where the
generator didn't infer unique-index validation from the schema. Nothing
exists yet for `Product` or `Order`, or anything else that touches money or
stock.

`User` also implements `Filament\Models\Contracts\HasName`
(`getFilamentName()`), required because `FilamentManager` falls back to a
`name` attribute this schema doesn't have — see `troubleshooting.md` for
the crash this produced before it was added.

Roles come from `spatie/laravel-permission`. `User` uses the `HasRoles`
trait; `canAccessPanel()` checks `hasAnyRole(User::STAFF_ROLES)`. Verified
working — staff reach the panel, a customer does not, and a user can hold
two roles at once.

The three staff role rows (`administrator`, `content_editor`,
`warehouse_employee`) are seeded by `database/seeders/RoleSeeder.php`,
called from `DatabaseSeeder`, so a fresh `migrate:fresh --seed` now produces
them in every environment. `PermissionSeeder` runs before it with a
catalogue of 108 permissions named `{ability}_{resource}`, where the ability
half matches the Laravel policy method that checks it — which is what keeps
a policy method to one line. `UserSeeder` then creates one account per role
plus a plain customer, gated to non-production; see ADR-0003 on why seeded
credentials and seeded reference data get different treatment.

`content_editor` holds 20 permissions and `warehouse_employee` 12, from §3.3
and §3.4. `administrator` holds none: a `Gate::before` callback in
`AppServiceProvider` returns `true` for that role and short-circuits every
check, so the role does not drift out of step with the catalogue as
permissions are added. The cost is that a policy can no longer deny an
administrator anything, which pushes "nobody may do X" rules into the
Actions as domain invariants.

Seven Policy classes gate the seven Resources, one per model, each method a
single `$user->can('{ability}_{resource}')`. They check permissions rather
than role names because §3.5 requires permissions editable at runtime — a
`hasRole()` check would go stale the moment an administrator edits a role.
Laravel resolves them by convention, so nothing registers them.
`tests/Feature/RolePermissionTest.php` covers the matrix, weighted toward
the denials, and asserts that every model with a Resource resolves a policy
at all: Filament reads authorization off the policy, so a missing one fails
open.

Blueprint has generated the schema from `online-store/draft.yaml`: 40
migrations, 32 models, 32 factories. `migrate:fresh` applies cleanly and
every factory persists a row, which `tests/Feature/FactoryTest.php` now
asserts rather than leaving to a manual check.

`App\Enums` holds 12 backed enums covering every `enum` column in the schema.
The 12 models with such columns cast them, and the factories draw from
`Enum::cases()`. All 12 implement Filament's `HasLabel`; seven also implement
`HasColor`. Four of them — `OrderStatus`, `PaymentStatus`, `ShipmentStatus`,
`ArticleStatus` — carry a transition matrix; see ADR-0004 for where the rest of
the state machine is meant to live.
Beyond enum casts and relations the models remain data structures — no
Actions, and nothing yet calls `canTransitionTo()`.

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
