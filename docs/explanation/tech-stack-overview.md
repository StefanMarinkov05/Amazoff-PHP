# Tech stack, at a glance

What this is built with, and why, in short. Full reasoning is in ADR-0001.
This page describes what's actually in the codebase now — for the parts
that are decisions rather than built code, ADR-0001 has the plan.

## Built and running

Laravel 13 on PHP 8.4, in Docker — `app`, `webserver`, `db`, `vite`,
`mailpit`. Verified running end to end: welcome page and Filament's
`/admin/login` both serve over the full nginx → PHP-FPM → MySQL chain.

Filament is installed and its panel provider registered.
`canAccessPanel()` on `User` gates it by role. 17 Resources exist:
the catalogue's lookup entities — `Brand`, `Tag`, `ProductCategory`,
`ArticleCategory`, `Attribute`, `AttributeValue`, `Carrier` — scaffolded with
`make:filament-resource --generate` and corrected by hand where the
generator didn't infer unique-index validation from the schema; `Product`,
with `ProductVariation`/`ProductImage`/`ProductSpecification` as relation
managers rather than resources of their own; `Coupon`; the read-mostly
`ContactMessage` and `NewsletterSubscriber`; `Article`, full CRUD, with its
status-change menu generated from `ArticleStatus`'s transition matrix rather
than hand-written; `ProductReview`, moderation only (approve/unapprove,
no create); `Order`, with no create, edit, or delete — status is the one
mutable field, and it moves through a **Change status** menu generated from
`OrderStatus`'s transition matrix, on both the table row and the view page,
calling `TransitionOrderStatus`. The menu authorizes per *target* status
rather than once for the whole group, because `OrderPolicy::updateStatus()`
routes by target: ADR-0011 makes cancelling and refunding administrator
moves, so a warehouse employee is never offered either; `Inventory`,
view-only plus two row actions (`adjustStock`/`recordDamage`, both existing
Actions) rather than a form — `InventoryPolicy`'s own docblock states why a
quantity is never a direct write; and one over spatie's `Role`, described
under authorization below.

`Shipment` and `User` landed alongside it. `Shipment` closes §37 criterion
15 — view and status changes at `admin/shipments`, and creation as an action
on the *order's* page, because §28 refuses a shipment for a cancelled,
unpaid, or already-shipped order and a standalone create form would invite
picking one the Action then refuses. `User` is accounts and role assignment,
with no create page (an account exists because someone registered) and no
delete (removing one orphans its orders, and §19 needs that history) —
deactivation via `is_active` is the reversible path.

`User` also introduced the `assignRole_user` ability. Granting a role is how
an account gains panel access, so folding it into `update_user` would let
every holder of `update_user` promote themselves; `UserPolicy`'s docblock
had flagged this as the thing to fix when the resource was built. The
"you may not change your own roles" half of the rule lives in
`EditUser::mutateFormDataBeforeSave()` rather than the policy, because
`Gate::before` short-circuits every check for an administrator — the one
role that holds the ability — so a policy-level guard would be dead code.
That is the ADR-0006 consequence this document already describes, met in
practice for the first time.

`Inventory` exists because `update_inventory` did not, for
`warehouse_employee`, reach anything: the only panel path to `AdjustStock`
was `ProductVariationsRelationManager`, nested under `ProductResource` and
gated by `viewAny_product`, which the role's permission set (§3.4) never
grants. The permission was real; nothing in the panel structure could
deliver it. `reference/permissions.md`'s "Where each check lives" table has
the fix; `changelog/CHANGELOG.md` has the finding in full.

`User` also implements `Filament\Models\Contracts\HasName`
(`getFilamentName()`), required because `FilamentManager` falls back to a
`name` attribute this schema doesn't have — see `troubleshooting.md` for
the crash this produced before it was added.

Roles come from `spatie/laravel-permission`. `User` uses the `HasRoles`
trait; `canAccessPanel()` checks `hasAnyRole(User::STAFF_ROLES)`. Verified
working — staff reach the panel, a customer does not, and a user can hold
two roles at once.

The three staff role rows (`administrator`, `content_editor`,
`warehouse_employee`) are seeded by
`database/seeders/System/RoleSeeder.php`, called from `DatabaseSeeder`, so
a fresh `migrate:fresh --seed` now produces
them in every environment. `PermissionSeeder` runs before it with a
catalogue of 107 permissions named `{ability}_{resource}`, where the ability
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

20 Policy classes exist — one per resource the permission catalogue
names, rather than one per Resource that happens to be built, since a
missing policy fails open the moment someone scaffolds the resource. Most
methods are a single `$user->can('{ability}_{resource}')`; they check
permissions rather than role names because §3.5 requires permissions
editable at runtime, and a `hasRole()` check would go stale the moment an
administrator edits a role.

Six carry more than that. `OrderPolicy` and `PaymentPolicy` refuse creation
outright — an order exists because a customer checked out, a payment because
Stripe said so — and `OrderPolicy::view`, `ProductReviewPolicy::view`, and
`UserPolicy::view` add ownership branches, the per-record half §34 calls
preventing unauthorized resource access. `UserPolicy::delete` refuses
self-deletion, since removing the last administrator locks the panel against
everyone. `UserPolicy::assignRole` gates role assignment separately from
`update_user` — see the `User` resource above.

Laravel resolves policies by convention, with one exception:
`Spatie\Permission\Models\Role` is outside `App\Models`, so
`AppServiceProvider` registers `RolePolicy` by hand. Without it the model
that controls what every role may do would be the one ungated model in the
system.

`tests/Feature/RolePermissionTest.php` covers the matrix, weighted toward
the denials, and asserts that all 20 models resolve a policy at all.

A Filament resource over spatie's `Role` model makes §3.5 true rather than
architectural: an administrator changes what a role may do from
**Roles & permissions**, and the change is live on the next request. Edit
only — no create or delete, because `canAccessPanel()` gates on the
`User::STAFF_ROLES` constant and a role created in the UI would grant no
panel access until someone edited that constant and deployed.

ADR-0006 records why the four layers are separate and what the arrangement
costs — chiefly that `Gate::before` makes a policy unable to deny an
administrator anything, which pushes "nobody may do X" rules into the
Actions. `reference/permissions.md` lists the catalogue;
`how-to/edit-a-role.md` covers the panel and seeder paths and why they are
not equivalent.

Blueprint generated the schema from `online-store/draft.yaml`: 32 models, 32
factories. Migrations now number 51 — the generated set plus hand-written
ones added since (check constraints, composite pivot keys, the `contact_
messages` handling columns, dropping `coupons.times_used`, the
`order_status_histories` unique constraint, the variation image pivot and
dropping `product_variations.image_id`) — so this count moves often and
is worth recounting (`ls database/migrations | wc -l`) rather than trusting a
stale figure here. `migrate:fresh` applies cleanly and every factory
persists a row, which `tests/Feature/FactoryTest.php` asserts rather than
leaving to a manual check.

`App\Enums` holds 12 backed enums covering every `enum` column in the schema.
The 12 models with such columns cast them, and the factories draw from
`Enum::cases()`. All 12 implement Filament's `HasLabel`; seven also implement
`HasColor`. Four of them — `OrderStatus`, `PaymentStatus`, `ShipmentStatus`,
`ArticleStatus` — carry a transition matrix; see ADR-0004 for where the rest of
the state machine is meant to live. `OrderStatus::canTransitionTo()` is now
called, by `TransitionOrderStatus` — the other three matrices still have no
caller.

31 Actions exist across five areas (`reference/actions.md`).
Business logic lives there now, not on the models — the models remain data
structures with enum casts and relations, exactly as CLAUDE.md's
Actions-own-the-rules architecture requires.

Larastan and Pest are configured and passing against what exists so far.

## Installed, not wired in

`astrotomic/laravel-translatable` and `spatie/laravel-activitylog` are in
`composer.json` with no application code using them yet — no translation
storage, no audit log.

Checkout exists too — `CheckoutPage` and `OrderConfirmation`, §37 #6-#8 —
so the storefront now runs cart → checkout → carrier and office selection →
order → payment → intent → confirmation end to end. `docs/explanation/couriers.md`
covers the courier layer specifically.

**Stripe's SDK is no longer in this list.** `CreateStripeIntent`,
`HandleStripeWebhookEvent`, and `RefundPayment` use it, `AppServiceProvider`
binds `StripeClient` as a singleton, and `POST /stripe/webhook` is live
behind `VerifyStripeWebhookSignature`. Per ADR-0001 there is no interface
over it and no Saloon connector: one implementation, nothing to swap it for.

**Saloon is no longer in this list either.** `App\Contracts\CourierGateway`,
`EcontGateway`, and `SpeedyGateway` use it — see "Saloon, courier clients"
in ADR-0001 and `docs/explanation/couriers.md`.
The container binding exists so tests can substitute a fake, which is not
the same as an abstraction in `app/`. Purify is wired in too, by ADR-0015.
ADR-0001 has the reasoning for each; this is a statement that the reasoning
hasn't been tested against real code yet.

## Open

Content translation storage shape and audit log shape are undecided. PHP
version (`^8.3` declared, `8.4` actually required by the lockfile) is
unresolved.

Nothing calls `schedule:run` locally — no cron, no supervisor loop, in
`docker-compose.yml` or the `app` image. `routes/console.php`'s `Schedule::`
entries (`carts:expire`) are correct and tested, but won't fire in a local
container on their own; run `docker compose exec app php artisan
schedule:run` by hand to trigger due tasks, or `schedule:work` for a
foreground loop, while developing against one. Forge registers this
automatically in production per ADR-0001, so this only affects local dev.
