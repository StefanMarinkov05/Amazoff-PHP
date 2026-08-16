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
- `App\Enums` — 12 backed enums covering all 19 `enum` columns in the schema:
  `OrderStatus`, `PaymentStatus`, `PaymentMethod`, `ShipmentStatus`,
  `InventoryMovementType`, `ArticleStatus`, `CouponType`, `CouponScope`,
  `AddressType`, `DeliveryType`, `AttributeInputType`, `NewsletterStatus`.
  Each carries `values()` and implements Filament's `HasLabel`, so tables,
  filters, and select fields render them without a per-resource value map.
- `HasColor` on the seven enums where a badge colour carries meaning —
  `OrderStatus`, `PaymentStatus`, `PaymentMethod`, `ShipmentStatus`,
  `InventoryMovementType`, `ArticleStatus`, `NewsletterStatus`. The remaining
  five classify rather than describe state, where a colour would be decoration.
- `allowedTransitions()` and `canTransitionTo()` on the four lifecycle enums —
  `OrderStatus`, `PaymentStatus`, `ShipmentStatus`, `ArticleStatus`. Which
  enums get a matrix, why the other eight do not, and where the policy and
  enforcement halves belong are in ADR-0004. Nothing calls these yet.
- Enum casts on the 12 affected models, so the enums are authoritative in
  application code rather than decorative. Verified against MySQL: a status
  written as an enum case reads back as one.
- Factories use `Enum::cases()` in place of the literal value arrays Blueprint
  generated. A renamed case now fails at parse rather than on insert.
- `tests/Feature/FactoryTest.php` — persists one row from every factory,
  discovered by glob. This is the check `docs/how-to/regenerate-with-blueprint.md`
  describes as the only way to catch a factory writing a value its column
  cannot hold; it was documented but not automated.
- Six Filament resources over the catalogue's lookup entities: `Brand`,
  `Tag`, `ProductCategory`, `ArticleCategory`, `Attribute`, `AttributeValue`.
  Scaffolded with `make:filament-resource --generate`, then corrected by hand
  — see Fixed, below. `ProductCategory`'s self-referencing `parent_id` and
  `AttributeValue`'s `attribute_id` foreign key both resolved to `Select`
  fields backed by `relationship()` without manual intervention.
- `database/seeders/RoleSeeder.php` — creates the three `User::STAFF_ROLES`
  rows (`administrator`, `content_editor`, `warehouse_employee`). Runs in
  every environment, production included, since a role must exist before
  anyone can be assigned to it through the panel.
- `DatabaseSeeder` now creates a staff account and assigns it the
  `administrator` role, gated behind `! app()->isProduction()` — reference
  data (roles) seeds everywhere, a known-password test credential does not.
  Closes the gap the `Open` section below used to track.
- `User` implements `Filament\Models\Contracts\HasName`, alongside the
  existing `FilamentUser`. See Fixed, below, for why this was load-bearing
  rather than cosmetic.
- `docker/mysql/init/01-test-database.sh`, mounted into the `db` service's
  `docker-entrypoint-initdb.d/`. Creates `online_shop_test` and grants the
  app user access to it on first container initialization, matching what
  CI's MySQL service already provisions — local `pest` runs against a fresh
  clone without a manual `CREATE DATABASE` step.
- `database/seeders/PermissionSeeder.php` — 104 permissions named
  `{ability}_{resource}`, the ability half matching the Laravel policy method
  that checks it. Seeded in full rather than per built resource: §3.3 and
  §3.4 describe what a role may do, not what happens to be built, and
  `content_editor`'s deny-list is only meaningful if the permissions it
  excludes exist.
- `RoleSeeder` now attaches those permissions — 20 to `content_editor`, 12 to
  `warehouse_employee`, none to `administrator`. `syncPermissions()` rather
  than `givePermissionTo()`, so a permission removed from the seeder is
  actually revoked on the next run; the tradeoff is that a re-seed discards
  runtime edits made through the panel.
- `database/seeders/UserSeeder.php` — one account per role plus a plain
  customer, gated to non-production. §37 criterion 18 is only demonstrable
  with an account per role, and the customer is what proves
  `canAccessPanel()` denies someone holding no role at all.
- `Gate::before` in `AppServiceProvider` grants `administrator` every
  ability. Returns `null` rather than `false` when the role is absent, so
  other users still reach spatie's callback and then their policy.
- Twenty Policy classes — one per resource the permission catalogue names,
  not one per Filament Resource built, since a missing policy fails open the
  moment a resource is scaffolded. Most methods are one
  `$user->can('{ability}_{resource}')`, checking permissions rather than role
  names because §3.5 requires permissions editable at runtime. `Order` and
  `Payment` refuse creation outright; `Order`, `ProductReview`, and `User`
  add ownership branches; `User` refuses self-deletion. `RolePolicy` is
  registered by hand in `AppServiceProvider` because spatie's `Role` sits
  outside `App\Models` and convention does not find it.
- `tests/Feature/RolePermissionTest.php` — 32 tests over the §37 criterion 18
  matrix, weighted toward the denials, including that every model with a
  Resource resolves a policy at all.
- `docs/adr/0006-authorization-layers.md` — why panel access, permissions,
  policies, and the administrator exemption are four separate mechanisms, and
  what the arrangement costs.
- `docs/how-to/run-the-tests.md` — running one file or one test, the flags
  worth knowing, why the suite needs MySQL, and how to check that a test can
  actually fail.
- Filament resource over spatie's `Role`, satisfying §3.5 — permissions
  editable without a deploy, which until now described an arrangement nobody
  could exercise. Edit only: no create or delete, since `canAccessPanel()`
  gates on the `User::STAFF_ROLES` constant and a role created in the UI
  would grant no panel access until that constant changed. Permissions render
  as one checkbox list per resource, each scoped to its own names so several
  lists can edit the same relation without clearing each other.
- `App\Support\PermissionCatalogue` — the catalogue's shape, read by both
  `PermissionSeeder` and the roles form. Previously private constants on the
  seeder; the UI needed the same groupings.
- `docs/reference/permissions.md` — the 104 permissions, the three roles and
  what each holds, and which check answers which question.
- `docs/how-to/edit-a-role.md` — the panel path and the seeder path, why they
  are not equivalent, and what the screen deliberately refuses to do.
- `app/Actions/Inventory/` — `RecordInventoryMovement`, `ReserveStock`, and
  `ReleaseStock`, the first Actions in the codebase, plus
  `Inventory::available()` and `InsufficientStockException`. Both writing
  Actions take `DB::transaction` and `lockForUpdate`; the ledger writer
  deliberately opens no transaction, since a movement without the quantity
  change it describes is a lie and the caller owns the boundary.
- `tests/Concurrency/`, a testsuite of its own, because `RefreshDatabase`
  rolls back rather than commits and a second connection cannot see rows that
  were never committed. The race test runs two OS processes against a shared
  wall-clock barrier and asserts the *type* of the loser's exception — both
  the locked and unlocked versions produce one winner, and only the locked one
  fails cleanly.
- `docs/adr/0007-action-conventions.md` — the actor is a nullable last
  parameter and null means the system; events dispatch after commit; an Action
  is required where a rule spans tables rather than everywhere; composition
  nests via savepoints.
- `docs/explanation/inventory.md` and
  `docs/explanation/concurrency-and-locking.md` — the stock counters and the
  locking that protects them, including why `increment()` rather than
  arithmetic in PHP is load-bearing: the constraint catches the first case as
  a 500 and cannot catch the second at all.
- `docs/how-to/start-a-session.md` — a session prompt for Claude Code, with
  the reasoning for each instruction so it can be edited rather than copied
  once and left to go stale.
- Filament resource over `Product`, with `ProductVariation`, `ProductImage`,
  and `ProductSpecification` as relation managers rather than resources of
  their own. None is browsed independently of its product, and a standalone
  resource would let a variation be created without one. Relation managers
  only render on the Edit page — a child row needs its parent's id, which
  does not exist while the create form is open.
- `ProductImagePolicy` and `ProductSpecificationPolicy`, checking
  `view_product` and `update_product` rather than permissions of their own.
  Managing a product's images or specifications is editing that product, and
  §3 describes no role that draws a line between them. A distinct class is
  still required: `ProductPolicy::update()` is type-hinted to `Product` and
  cannot receive a `ProductImage`. `ProductVariation` keeps its own
  permission set, because §3.4 gives the warehouse a reason to read SKU and
  stock without holding the catalogue's descriptive content.
- Filament resource over `Coupon`, satisfying §11's discount codes. Fields
  react to each other: `value` renders as `%` or `EUR` and caps at 100 or the
  column ceiling depending on `type`, `max_discount_amount` appears only for
  percentage coupons, and the product and category pickers appear only under
  the matching `scope`. `times_used` is displayed but never submitted —
  `disabled()` plus `dehydrated(false)`, since an editable counter would let
  an exhausted coupon be reopened by typing a smaller number.
- Table filters, the first in any resource: `type`, `scope`, and `is_active`
  on coupons.
- Filament resources over `ContactMessage` and `NewsletterSubscriber`, the
  first read-mostly ones: no create page, no create action, and a View page
  with an infolist — also the first infolists in the panel. Both arrive from
  public forms (§5, §26), so creating one by hand would fabricate a record
  the sender never submitted.
- `contact_messages.handled_at` and `internal_note`, in a new migration.
  `ContactMessagePolicy::update()` already described "marking handled or
  attaching an internal note", but the columns it assumed did not exist, so
  the edit screen's only effect was rewriting the sender's own words. The
  customer's fields are now `disabled()` and `dehydrated(false)`; only the
  two staff columns are writable. `handled_at` is a nullable timestamp
  rather than a boolean — when a message was dealt with is worth more than
  that it was, and null already means outstanding.

### Changed

- Local database container runs with relaxed durability
  (`innodb_flush_log_at_trx_commit=2`, `sync_binlog=0`, `--skip-log-bin`).
  Production is unaffected — it runs on Forge with MySQL's defaults. A single
  `CREATE TABLE` + `DROP TABLE` inside the container went from 8.28s to 3.16s;
  `migrate:fresh` from 6m51s to seconds.
- `docker/php/Dockerfile` installs Oracle's `mysql-client` rather than Debian's
  `default-mysql-client`, which is MariaDB's and rejects the flags Laravel
  passes to `mysqldump`. `schema:dump` now works, which lets `migrate:fresh`
  load a schema dump instead of replaying every migration.
- `Table::configureUsing()` in `AppServiceProvider` sets `defaultCurrency`
  to EUR. Filament's `money()` columns fall back to `usd` when given no
  argument, so this is set once rather than passed to every money column,
  where a new table would silently render dollars.
- `AssociateAction` and `DissociateAction` removed from all three product
  relation managers. `--generate` scaffolds them, but `product_id` is NOT
  NULL on all three child tables, so no row is ever unattached and
  "associate" could only mean reassigning another product's image, spec, or
  variation to this one. Nothing in §6–7 asks for that. They also bypass
  policies entirely — Filament checks only `isReadOnly()` for them — so
  gating rather than removing would have needed a second mechanism.

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
- `make:filament-resource --generate` does not infer unique-index validation
  from the schema. All six generated forms had a `slug` field with no
  `->unique()` rule despite a database-level unique constraint on every one
  of them; `AttributeValueForm` needed a composite rule
  (`modifyRuleUsing`) to match `attribute_values`' `UNIQUE(attribute_id,
  slug)` rather than a plain column-level check. Corrected by hand in all
  six resources.
- `AttributeValueForm.php` imported `Filament\Forms\Get`, which does not
  exist in Filament v4 — `Get`/`Set` moved to
  `Filament\Schemas\Components\Utilities\Get`. Pint and the IDE (which
  cannot resolve any vendor class from the host — see `troubleshooting.md`)
  both missed it; Larastan caught it as `class.notFound`. Would otherwise
  have failed at runtime the first time the closure using it ran.
- `FilamentManager::getUserName()` threw a `TypeError` on every panel page
  after login. It falls back to reading a `name` attribute when the
  authenticated model does not implement `HasName`, and this schema has no
  `name` column — only `first_name`/`last_name`. Fixed by implementing
  `HasName::getFilamentName()` on `User`.
- `DatabaseSeeder` passed `'name' => 'Test User'` to a `users` table with no
  `name` column — silently discarded by Eloquent rather than erroring (see
  the seeded-column entry in `troubleshooting.md`). Replaced with a seeder
  that sets `first_name`/`last_name`, matching the actual schema.
- Every reactive field in `CouponForm` compared `$get('field')` against an
  enum's `->value`. Filament casts an enum-backed `Select`'s state to a
  `BackedEnum`, so each comparison was an object against a string and never
  matched: the percentage cap never applied and 105 reached the database as
  a `CHECK` violation, and `max_discount_amount` and both scope pickers were
  permanently invisible. `Get::enum()` reads either representation. See
  `troubleshooting.md` — Pint and Larastan pass on both versions.
- `decimal:2` on every money field in `ProductForm` and the variations
  relation manager. With one parameter Laravel's rule means *exactly* that
  many decimal places, so a round `20` was rejected. Now `decimal:0,2`.
- `ProductForm` accepted a `discount_price` above `regular_price`, which the
  `CHECK` constraint then rejected as a 500. Now `->lt('regular_price')`.
  The same rule is deliberately absent on variations: a null variation price
  inherits the product's, and the constraint permits a discount alongside it,
  so a naive comparison would reject rows the database accepts. Resolving the
  effective price belongs in an Action.
- `ContactMessage` and `NewsletterSubscriber` still offered a create button
  after their create pages and routes were removed. `CreateAction` lives on
  the `ListRecords` page, not in `getPages()`, and with no route to link to
  Filament rendered it as a modal — which then failed on insert. Both
  policies already refused `create()`, but `Gate::before` grants an
  administrator every ability before any policy runs, so removing the action
  is the only thing that actually holds.
- Product forms and the three relation managers had no `maxLength` on any
  string field. The database rejects the overflow with the truncation error
  described at the top of `troubleshooting.md`; nothing client-side stopped
  it.
- `public/css/filament` and `public/fonts/filament` existed as empty
  directories — the compiled assets were never published, so every asset
  request 404'd and the panel rendered unstyled. `php artisan
  filament:assets` now runs as part of setup; see `README.md`.

### Removed

- `App\Enums\Role`, `App\Models\UserRoleAssignment`, and the `user_roles`
  migration. The enum + pivot approach was built first, then replaced by
  `spatie/laravel-permission` — §3.5 requires runtime-editable permissions.

### Open

- PHP version: `composer.json` declares `^8.3`, the lockfile requires 8.4.
  Not pinned.
- Content translation storage shape and default locale.
- Audit log shape.
- Enum value lists exist in two places: the 19 `enum()` literals in the
  migrations, and `App\Enums`. The migrations are frozen by the append-only
  rule, so the duplication cannot be removed retroactively. Migrations added
  from here on should use `OrderStatus::values()` rather than a literal array,
  which keeps the copy generated instead of typed.
