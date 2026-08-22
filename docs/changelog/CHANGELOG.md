# Changelog

Format follows [Keep a Changelog](https://keepachangelog.com/). Dates are
when the work happened, not when it was committed — nothing in
`Unreleased` has a git commit yet.

## Unreleased

### Added

- `app/Actions/Content/PublishArticle.php`, closing §37 criterion 17. Moves
  an article through §22's lifecycle — draft, scheduled, published, archived
  — checking `ArticleStatus::canTransitionTo()` before the write.
  `publish_article` is a permission separate from `update_article`
  (`content_editor` holds both, but a `Select` on `status` would have
  checked `update` and skipped the matrix), so this is an Action below
  ADR-0007's usual multi-table bar, built anyway because the authorization
  is the entire point of the operation. `published_at` is stamped the first
  time an article reaches Published and never rewritten — it answers "when
  did readers first see this," which an unpublish-and-republish does not
  change. Verified: `content_editor` can transition, `warehouse_employee`
  is refused with `AuthorizationException`, an illegal move throws
  `ArticleTransitionNotAllowedException`, and `published_at` survives a
  Published → Draft → Published round trip unchanged.
- Filament resource over `Article`, full CRUD — the panel's first, since
  every prior resource this session was either lookup-table CRUD or
  deliberately read-only. `author_id` is NOT NULL and never a form field;
  `CreateArticle::mutateFormDataBeforeCreate()` sets it from `auth()->id()`.
  No `mutateFormDataBeforeSave()` on the edit page — that would reassign
  authorship to whoever last touched the record, which `updated_at` already
  answers. `status` and `published_at` are absent from the form entirely;
  both are `PublishArticle`'s alone.
- The status-change menu on `ArticlesTable` is generated from
  `ArticleStatus::cases()` rather than hand-written, one button per case.
  `visible()` calls the same `canTransitionTo()` the Action enforces, so
  the menu can only ever offer legal moves and the matrix stays the single
  place the rule lives — widening the enum widens the menu with no second
  edit. The Action still re-checks on click; a hidden button is UX, not the
  guarantee.
- `Article::content` uses `RichEditor`, the panel's first rich-text field
  (§22 — headings, lists, links, images, quotes, tables, embedded video,
  code blocks). Sanitising it is deliberately **not** done here: `CLAUDE.md`
  places Purify at render time, and nothing renders an article yet — a
  write-time cast would be a second, earlier answer to a question
  render-time already owns.
- `app/Exceptions/ArticleTransitionNotAllowedException.php` — carries both
  ends of the refused move as `ArticleStatus` instances rather than
  strings, so a catcher can build its own message from `getLabel()`.
- `@property ArticleStatus $status` on `Article`. Without it Larastan
  inferred the raw `enum()` literal union instead of the cast, rejecting
  `$article->status->canTransitionTo($to)` as "cannot call method on
  string" even though the runtime type is correct — proven with
  `PHPStan\dumpType()`, which is also how the fix (mirroring `Coupon`'s
  existing `@property CouponType $type`) was found rather than guessed.
  `Order::$status` has the identical gap, uncaught until whoever writes
  against it hits the same error.
- `app/Actions/Catalogue/DeleteProductCategory.php` — the one Action
  `ProductCategory` needed despite CLAUDE.md's plain-lookup-table exemption.
  `ProductCategoryPolicy::delete()`'s own docblock had already named the
  gap: whether a category could be deleted at all lived in a Filament
  `visible()`/`disabled()` closure, read once when the row rendered, with
  its `children()->count()` half commented out because `ProductCategory`
  had no `children()` relation. Added the relation, the Action
  (`ProductCategoryCannotBeDeletedException`, `lockForUpdate()` on the
  category before counting live children and products), and moved the
  guarded delete from the list table's row action to `EditProductCategory`'s
  header, matching `EditProduct`'s existing shape — a static Table class has
  no `$this` for `ReportsDomainFailures` to bind to, a Page does.
- `tests/Concurrency/DeleteProductCategoryConcurrencyTest.php` — deleting a
  category while a subcategory is created underneath it. Asymmetric
  deliberately, and says so: the create side is plain Eloquent, so its
  failure mode is a raw `QueryException` off `parent_id`'s foreign key, not
  a domain exception. Measured, not assumed: without a second, tighter
  rendezvous on top of the usual wall-clock barrier, the create side won
  every time — boot jitter alone was deciding it, the trap
  `AddToCartVsMergeGuestCartConcurrencyTest` already names for a different
  pairing. With the rendezvous both sides win a real share. Also recorded
  honestly: deletion-proofing the Action's lock across fourteen attempts
  never forced the one failure that would prove it necessary — the window
  is narrower than this harness can reliably hit, the same conclusion
  `write-rules/order.md` already reached for the deadlock-prevention sort.
  The lock stays regardless, on the same reasoning `ReserveStock`'s does.
- `docs/reference/write-rules/product-category.md` — the outcomes page for
  the above.
- `misc/variation-images-plan.md` — draft plan for giving product variations
  their own optional image gallery, additive to the existing product-level
  one. Two schema-shaping questions left open for explicit sign-off before
  any migration is written.
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
- Filament resource over `Order`, read-only, with `orderItems`,
  `orderStatusHistories`, and `orderAddresses` as read-only relation
  managers. `OrderPolicy` refuses create (§12 — an order exists because
  checkout ran) and delete (§19 — the history has to survive), and there is
  no edit page because the only legitimate write is a status change, which
  belongs in `TransitionOrderStatus`. **Criterion 16 is therefore not met
  yet**: the screens read orders, nothing moves one. Order items are the §18
  price snapshot and status history is the §19 audit trail, so neither is
  hand-editable by design.
- `OrderAddressesRelationManager` composes one readable address line rather
  than listing six columns, branching on `DeliveryType` — a home delivery
  fills `street`, a courier pickup fills `courier_office_*`, never both.

- `docs/adr/0009-code-coverage.md` — PCOV for `pest --coverage`, chosen over
  Xdebug by benchmarking both against this suite specifically (+13% on
  `tests/Concurrency/`, +36% on a fast in-process run) rather than assuming
  the difference. Reported as a CI artifact, never gated — no `--min`
  threshold, consistent with this project's existing position that a
  passing test is not evidence without deletion-proof.
  `docker/php/conf.d/pcov.ini`, `docker/php/conf.d/cli-memory.ini` (the
  default 128M `memory_limit` cannot assemble a full-project report).
- `docs/reference/coverage.md` — per-class PCOV breakdown, distinguishing
  lines proven by a concurrency test PCOV cannot see from lines genuinely
  untested.
- `docs/reference/write-rules/` — `product-write-rules.md`,
  `cart-write-rules.md`, and `concurrency-coverage.md` moved here as
  `product.md`, `cart.md`, `concurrency.md`. The three cross-reference each
  other constantly and shared a naming pattern already; ~25 other files
  citing the old paths updated.
- `tests/Concurrency/ReleaseStockConcurrencyTest.php`,
  `ForceDeleteProductVariationConcurrencyTest.php`,
  `MergeGuestCartConcurrencyTest.php` — three mechanisms
  (`lockForUpdate()`, a catch-and-retry) that existed in application code
  but had never been raced by two real processes. All three verified by
  deletion.
- `tests/Concurrency/AddToCartVsMergeGuestCartConcurrencyTest.php` — races
  `AddToCart` against `MergeGuestCart` directly rather than each against
  itself. Proved the collision is real and `AddToCart`'s retry handles it;
  could not prove `MergeGuestCart`'s retry in this specific pairing across
  24 attempts under three synchronization strategies — `MergeGuestCart` has
  no domain validation before its insert and wins every time in this
  environment. Recorded as a measured, narrower gap rather than claimed as
  fully verified. `docs/explanation/concurrency-and-locking.md` gained a
  section on why a cross-Action race needs a rendezvous beyond the usual
  wall-clock barrier.
- Tests closing three branches no test exercised: `ForceDeleteProduct`'s
  refusal when a product has reviews, `RemoveProductImage`'s authorization
  check (nothing had ever called it with a non-null actor), `ReleaseStock`'s
  `quantity < 1` guard (present and tested on `ReserveStock`, missing on its
  sibling).
- `app/Actions/Order/TransitionOrderStatus.php` — the only writer of
  `orders.status`, closing the gap ADR-0004 designed and left unbuilt. Checks
  `OrderStatus::canTransitionTo()` for legality, `OrderPolicy::updateStatus()`
  for authorization (routed by target status — `cancel_order`/`refund_order`
  for `Cancelled`/`Refunded`, `updateStatus_order` otherwise), writes the §19
  history row, applies the target status's inventory effect, and dispatches
  `OrderStatusChanged` after commit. `$from === $to` is a clean no-op rather
  than a refusal, checked after authorization so a denied actor cannot infer
  a move's legality from a silent success. Locks `orders`, re-reading
  `status` from the locked row rather than the `Order` instance passed into
  `handle()` — the one subtlety that compiles either way and only a
  two-process test can tell apart; see
  `explanation/concurrency-and-locking.md`, "Trusting the locked row, not the
  reference that was locked."
- `app/Actions/Inventory/CompleteSale.php` and `RestockReturn.php` — the two
  movement directions §20's schema always had (`sold_quantity`,
  `returned_quantity`, `InventoryMovementType::CompletedSale`/
  `CustomerReturn`) and nothing wrote to before now. Composed by
  `TransitionOrderStatus` on `=> Shipped` and `=> Returned`; ADR-0011 records
  why they live inside that Action rather than behind a `CancelOrder`/
  `ShipOrder` wrapper. `CompleteSale` decrements `reserved_quantity` before
  `current_quantity` — the reverse order can violate
  `chk_inventories_reserved_not_above_current` mid-transaction when a sale
  empties fully-reserved stock, and nothing static catches the ordering;
  `CompleteSaleTest`, "completes a sale that empties the entire
  reserved-equals-current stock" is deletion-proofed against it.
- `App\Exceptions\IllegalOrderStatusTransitionException` and
  `App\Events\OrderStatusChanged` — the first class in `app/Events`, so the
  storefront/panel/webhook has a convention for `ShouldDispatchAfterCommit`
  (ADR-0007) to follow rather than inventing one under time pressure. No
  listeners yet; §28's queued emails are a later slice.
- `cancel_order` and `refund_order` permissions, in
  `PermissionCatalogue::DOMAIN_ABILITIES`. Catalogue grows 104 → 106.
  `warehouse_employee`'s seeded grant list is unchanged — it holds neither,
  by design, relying on `Gate::before` to grant an administrator both rather
  than an explicit row.
- A migration adding `UNIQUE(order_id, new_status)` to
  `order_status_histories` — the backstop for `TransitionOrderStatus`'s
  `orders` lock, in the same relationship
  `chk_inventories_reserved_not_above_current` has to `ReserveStock`. Depends
  on `OrderStatus`'s transition graph being acyclic, which
  `tests/Unit/Enums/TransitionMatrixTest.php` now asserts algorithmically
  (a DFS cycle check) rather than only by the file's existing hand-written
  legal/illegal table, which — by the file's own stated reasoning — could be
  edited in step with a matrix change that introduced a cycle and still pass.
- `docs/adr/0011-order-status-side-effects.md` — the inventory consequence of
  a status transition lives inside `TransitionOrderStatus`, keyed by target
  status, rather than in a `CancelOrder`/`ShipOrder` wrapper; records the
  explicit departure from ADR-0004's original illustrative example and why.
- `tests/Concurrency/TransitionOrderStatusConcurrencyTest.php` — two staff
  transitioning one order at once, closing the gap
  `reference/write-rules/concurrency.md` had listed as "Not covered." Two
  races, not one: identical concurrent transitions (both succeed, exactly one
  write — the no-op design means this is not a winner/loser shape at all,
  see `explanation/concurrency-and-locking.md`, "A fourth shape: idempotent
  no-ops") and two different, mutually-exclusive transitions from the same
  origin (ordinary winner/loser). The second race deliberately avoids
  `Cancelled` as either side — it is reachable from almost every status, so
  a pairing including it is not reliably exclusive.
- Closed three of `write-rules/order.md`'s five original `CreateOrder`
  "Known gaps," all the same idempotency shape (a constraint plus a caught
  violation, never check-then-act):
  - `orders.cart_id` (`UNIQUE`, nullable, no foreign key) plus
    `CartAlreadyCheckedOutException` — the same cart checked out twice, once
    a deliberately-pinned gap with its own concurrency test, now a
    guarantee. `CreateOrderConcurrencyTest`'s third race rewritten from
    asserting two orders to asserting one order and a clean refusal for the
    loser.
  - `CheckoutActorRemovedException`, a caught `QueryException` on the
    `orders_user_id_foreign` constraint — a hard-deleted actor between being
    read and the `orders` insert now refuses cleanly instead of surfacing a
    raw `QueryException`. `CreateOrderTest`'s gap-pinning test rewritten to
    assert the new exception.
  - `CouponNotApplicableException::noLongerExists()` — a coupon whose row is
    gone by checkout now refuses rather than silently proceeding at full
    price. Discovered while writing its test that this is defence in depth
    rather than a reachable gap: `carts.coupon_id`'s own foreign key has no
    cascade, so an ordinary `$coupon->forceDelete()` while any cart still
    applies it already fails at the database with error 1451 — the test
    constructs the state by disabling FK checks around the delete, the
    technique the concurrency suites use for truncation, precisely because
    the ordinary path is already closed. `coupon` is now nullable on the
    exception, since this one refusal has no row left to carry.
  - The remaining two gaps (the deadlock-prevention sort's evidence gap,
    `shipping_amount` hardcoded pending courier integration) are unchanged —
    neither is a mechanical correction.
- `app/Actions/Inventory/RecordDamage.php` — the last of §20's ledger
  directions nothing wrote to, closing the status-transition "Known gaps"
  entry on damaged returns halfway: `current_quantity` to
  `damaged_quantity`, general-purpose like `ReserveStock`/`ReleaseStock`
  rather than composed by `TransitionOrderStatus`. Guards `available()`
  (current minus reserved), not `current_quantity` alone — damaging reserved
  stock would push `reserved_quantity` above `current_quantity`, the same
  `CHECK` constraint `CompleteSale`'s decrement ordering already respects.
  No caller composes it and no admin surface triggers it yet; a damaged
  return is still `RestockReturn` followed by a separate manual
  `RecordDamage` call, not a single automatic path.

### Changed

- CI split into three parallel jobs (ADR-0010): `lint` (Pint, Larastan, no
  database), `test` (`tests/Unit` + `tests/Feature`, 2-shard matrix), and
  `test-concurrency` (`tests/Concurrency`, 3-shard matrix). Both suites'
  shards are hand-partitioned by measured wall-clock time, not split
  evenly by file count — each suite has one file whose cost would
  otherwise land wherever alphabetical order put it: one
  `tests/Concurrency` file using `->repeat(6)` is over half that suite's
  time; `tests/Feature/RolePermissionTest.php`'s `beforeEach` reseeds
  three seeders before every test (deliberate — the permission registrar
  caches for 24h) and is over a third of the Feature/Unit suite's time.
  Coverage collection dropped from CI entirely — sharding `test` means no
  single shard's report matches `reference/coverage.md`'s numbers, and
  merging two partial reports is real infrastructure for a number ADR-0009
  already established nothing gates on. Regenerate locally
  (`docs/reference/coverage.md` has the command) when the numbers are
  needed.
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

- `MergeGuestCart` had no collision handling at all — unlike `AddToCart`,
  which it otherwise mirrors, a concurrent merge or an unrelated `AddToCart`
  landing on the same line surfaced as an uncaught `QueryException`. Now
  catches and retries as an update, same shape as `AddToCart`. The fix
  needed one subtlety `AddToCart`'s doesn't: the retry runs as a savepoint
  inside the merge's own outer transaction, and a savepoint rollback does
  not refresh the transaction's `REPEATABLE READ` snapshot the way a fresh
  top-level transaction does, so the retry's read must `lockForUpdate()`
  rather than read plainly.
- `CalculateCartTotals` threw an uncaught `TypeError` on any cart line whose
  variation or product had been soft-deleted after the line was added — a
  realistic, previously-untested case. Now skips the line rather than
  crashing the cart total.
- `ReportsDomainFailures` (turns an Action's domain exception into a
  Filament notification) caught `RuntimeException` only. Seven of the eight
  domain exceptions extend it; `InvalidCartQuantityException` deliberately
  extends `InvalidArgumentException` instead, per its own docblock, written
  before this trait existed. Would have reached a Filament page as an
  uncaught exception rather than a notification the moment Cart got a
  caller that used the trait — silent only because no such caller exists
  yet. Now catches both.
- `pest --coverage` failed outright everywhere — CI set `coverage: none`
  explicitly and no driver was installed locally.

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
- `App\Models\Order` had no `@property` docblock naming its three enum-cast
  columns, so Larastan inferred `status`/`payment_status`/`payment_method` as
  raw DB-enum string unions instead of `OrderStatus`/`PaymentStatus`/
  `PaymentMethod` the moment `TransitionOrderStatus` read one back and called
  an enum method on it — `troubleshooting.md`'s "Larastan reports an enum
  comparison as always false" entry had already named `Order` as "the next
  likely case" once this Action existed. Added the three annotations,
  matching `Coupon`'s existing precedent.
- `docs/explanation/tech-stack-overview.md` — said "Nothing exists yet for
  `Product` or `Order`" and "no Actions" under Filament resources, both
  several slices stale (twelve resources and 24 Actions exist). Corrected in
  the same pass as this slice, since it is the page the next session reads
  to decide what is safe to build on.

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
