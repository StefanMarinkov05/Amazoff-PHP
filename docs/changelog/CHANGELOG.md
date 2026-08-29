# Changelog

Format follows [Keep a Changelog](https://keepachangelog.com/). Dates are
when the work happened, not when it was committed — nothing in
`Unreleased` has a git commit yet.

## Unreleased

### Added

- The product detail page — `Catalogue\ProductDetails` at
  `/products/{product:slug}`, closing §37 criterion 4's product side. Gallery
  with thumbnails and prev/next arrows, an attribute picker that resolves a
  selection to a variation, price and stock for that variation,
  specifications, approved reviews, an arbitrary-depth category breadcrumb,
  and add-to-basket through the existing `AddToCart` Action.

  The selection is held as **one** value — `variationId`, in the URL as `?v=`
  — and which attribute values are picked is derived from it. An earlier
  draft stored both a `selectedValues` map and the variation id; they can
  disagree the moment a shared link arrives with `?v=` and nothing has
  populated the map. One source of truth removes the failure rather than
  synchronising it. `variation()` is the validation gate: an id resolves only
  if it is in `variations()`, which is already scoped to this product and to
  `is_available`, so an id belonging to another product returns null instead
  of leaking its price. Same reasoning as the catalogue's sort allow-list,
  and for the same reason — `#[Url]` makes a property attacker-controlled.

  Two decisions worth naming. `gallery()` returns the variation's own images
  **followed by** the product's remaining ones rather than one or the other:
  ADR-0013 makes an image with no pivot row a product-level image,
  legitimately shown for any variation, and returning only the variation's
  own set collapsed the thumbnail strip and the arrows together whenever a
  variation owned a single photo — which reads as the page breaking rather
  than as a shorter gallery. And `addToCart()` does not pre-check stock:
  `AddToCart` re-validates availability, minimum quantity and stock inside a
  transaction, while `$this->stock` is read outside one and is stale on
  render. Two checks would be two answers that can disagree, and only one of
  them is authoritative.

- `App\Support\ResolveProductPrice` and `App\Support\ProductPrice` — the
  product-level counterpart to `ResolveVariationPrice`, and the value object
  both now return. `ProductPrice::make()` derives all four display values
  (price to charge, price to strike through, whether a sale is live, and the
  whole-percent saving) from one reading of the discount window, so nothing
  downstream can hold a `percent` that disagrees with its `current`.

  This removed a real duplication: `ProductList::discountIsActive()` had its
  own copy of §11's discount-window rule, which `ResolveVariationPrice`
  already owned. Two implementations of one rule can disagree, and the way
  they disagree is expensive — a card advertising a sale price the cart then
  refuses to honour. The window now lives in
  `ResolveProductPrice::windowActive()` and `ResolveVariationPrice` calls
  through to it.

  The percentage is computed with `bcmath` rather than float. The rounding
  boundary is reachable with ordinary prices: 200.00 down to 189.00 is
  exactly 5.5%, and in binary floating point that lands either side of the
  boundary depending on representation error, so the badge would read −5% or
  −6% unpredictably.

- `App\Support\ResolveCurrentCart` — finds or opens the current visitor's
  cart, keyed by `user_id` when signed in and `session_id` when not.
  `expires_at` is deliberately left null: `ExpireCarts` skips null rows, so
  this preserves today's behaviour exactly rather than choosing a guest-cart
  lifetime, which is a policy call belonging with the cart page.

- The storefront — first customer-facing slice, and the first code in this
  project outside the Filament panel. Livewire 3 + Tailwind 4, with
  `Catalogue\ProductList` at `/catalogue` covering §37 criteria 2 and 3
  (browse, search, filter, sort): search across name and short description,
  category and brand facets with live counts, in-stock and on-sale toggles,
  three sort columns, dismissible filter chips, and pagination. Every
  filter is `#[Url]`, so a filtered catalogue is a shareable link and the
  back button works.

  `ADR-0014` records the decisions this slice sets precedent for, because
  every remaining page copies its shape: reads query Eloquent directly from
  the component while writes still go through Actions (ADR-0007 is a rule
  about writes and stays one); filter state lives in the URL, never the
  session; a page that filters *and* counts holds one definition of
  "filtered"; and anything a URL-bound property reaches into a query is
  allow-listed, because `#[Url]` makes every public property
  attacker-controlled — `?sortBy=` lands in `orderBy()` otherwise.

  Two details worth naming separately. Facet counts are computed against
  every filter *except* the facet's own dimension (`applyFilters($q, skip:
  'categoryId')`), because counting against all of them makes every
  unselected category read zero the moment one is picked, and counting
  against none of them promises results the grid will not show; options
  that would count zero are dropped rather than shown greyed. And the
  availability figure subtracts `reserved_quantity` from
  `current_quantity` — a unit held for someone mid-checkout is not one this
  customer can buy, and showing it is how a catalogue promises stock that
  `ReserveStock` then refuses at the last step of checkout. The catalogue
  figure is explicitly not authoritative; it is read outside a transaction
  and stale on render. `ReserveStock` under ADR-0008's lock remains the only
  thing that decides.

- `DemoSeeder` — a catalogue that looks like a shop rather than a fixture.
  15 curated products across 16 categories (two levels) and 10 brands, with
  4 attributes / 15 values, articles, tags, coupons, and reviews. Products
  are created through `CreateProduct` and `AddProductVariation` rather than
  written directly, so the seeder exercises the same invariants the panel
  does and cannot produce a product the application considers invalid.

  It deviates from ADR-0003's JSON-fixture format deliberately and says so
  in its own docblock: the fixture format exists so test data is reviewable
  and diffable, and 15 hand-curated products with prose descriptions are
  neither improved by being moved into JSON nor covered by the tests that
  format serves. `purge()` truncates catalogue tables only, with
  `SET FOREIGN_KEY_CHECKS=0`, and explicitly leaves users and orders alone.

- `App\Support\PlaceholderImage` — deterministic SVG product imagery, drawn
  from the product's own name and category. ADR-0003 rules that no image
  binaries enter git; scraping a real shop would republish someone else's
  photographs and an external placeholder service would make seeding need
  network access. Hue comes from `crc32($name) % 360`, so the same product
  is the same colour on every re-seed, and one of 13 category silhouettes
  (headphones, speaker, turntable, keyboard, mouse, monitor, pan, kettle,
  lamp, backpack, tent, tool, box) gives the grid enough shape to read as
  merchandise. A contact shadow rather than a floating shape, because a
  grid of initials-in-a-box reads as a wireframe.

- Storefront chrome and design tokens — `x-site.header` (sticky, blurred,
  drawn SVG logo, category nav, cart badge, Alpine mobile drawer) and
  `x-site.footer`, on a layout with a skip link and a real `<main>`
  landmark. `resources/css/app.css` defines the palette as `@theme` tokens:
  an `ink` neutral ramp tinted toward the accent rather than pure grey, one
  committed `marine` blue, two radii, one easing curve. Two animations
  only — a staggered card entrance and a single hover sweep — both disabled
  under `prefers-reduced-motion`.
- `CatalogueStressSeeder` (`database/seeders/Stress/`) — thousands of
  additional products for catalogue-scale query-plan, pagination, and
  search testing, companion to the existing order-volume `StressSeeder`.
  Inserts directly (`DB::table(...)->insert()`) rather than through
  `CreateProduct`/`AddProductVariation`, at chunked-batch scale; every
  generated product still gets exactly one `is_default` variation and
  starts with `reserved_quantity = 0`, verified by placing a real order
  against a stress-generated product through `CreateOrder` directly, not
  assumed. Every product's single image row points at one shared
  placeholder file (copied from `public/images/logo.png` on first run,
  not a real photo) — catalogue-scale testing needs row count and query
  shape, not visual fidelity, and an API call per stress product would
  exhaust any free-tier image source in minutes. Categories and brands
  are reused from the existing pool rather than created per product.
  Opt-in only, like `StressSeeder`: never wired into `DatabaseSeeder`,
  never run in CI. Recorded as a deferred decision, not an oversight:
  generated variations carry no `attribute_values`
  (`docs/reference/schema/open-schema-questions.md` #6 — category/tag-driven
  attribute assignment does not exist for real catalogue content either).

### Fixed

- **`phpstan.neon` only ever scanned `app/`** — every "Larastan clean"
  claim made about `database/seeders/` this session was checking nothing,
  since the seeder reorg into `Demo/`/`System/`/`Stress/` and every seeder
  written or edited since landed outside Larastan's actual scan path.
  Added `database/seeders` to `paths`. Running it for real immediately
  found genuine bugs that had shipped silently: `DemoOrderSeeder` accessed
  `$item->productVariation`/`$item->quantity`/`$event->status` on
  untyped `Model` instances from an unannotated `foreach`, and carried an
  unused `TOTAL_ORDERS` constant; `DemoReviewSeeder`'s declared array
  shape for its (reviewer, product) pairs didn't match what the code
  actually built — it claimed `order_item_id` and never set it, and used
  `order_created_at` without declaring it, which `Carbon::parse()` was
  silently tolerating at runtime but is exactly the kind of drift static
  analysis exists to catch. All fixed; re-seeded the full demo pass
  afterward and confirmed every table still lands on its exact target
  numbers — the type annotations were wrong, not the runtime behaviour.

  Also added a scoped `ignoreErrors` entry for `database/seeders/*`:
  `Illuminate\Database\Seeder`'s `$command` property is declared
  `@var \Illuminate\Console\Command` (non-nullable) but is never
  initialized until `setCommand()` runs — the framework's own `run()`
  guards every use with `isset($this->command)` for exactly that reason.
  Every seeder in this codebase already uses `$this->command?->`
  correctly; Larastan trusts the docblock literally and flagged all of
  them as a redundant nullsafe call. A stub inaccuracy in one base class,
  suppressed by name and scoped to seeders only — not a blanket allowance
  for nullsafe operators elsewhere.

- `demo:fetch-images` — closes the gap where every one of the 182
  `product_images` rows pointed at a placeholder path with no real file on
  disk, breaking every product image in the browser. Downloads a real
  photo per row from Pexels, re-validated by hand against the exact
  constants `ProductImagesRelationManager`'s form enforces (the checks
  live only in the Filament form layer; this command bypasses that form
  the same way `FixtureLoader` does for the rest of the catalogue).

  Went through three sources before landing on one that actually works,
  and the command's own docblock keeps that record so nobody repeats it:
  Unsplash worked correctly (0 contamination across 25 files) but its
  50-requests/hour free tier meant several runs spread across a day to
  cover ~162 products; an Apify `hooli/google-images-scraper` actor was
  tried next for its lack of an hourly cap, and a real run showed roughly
  40% of "downloaded images" were actually a hotlink-protection
  placeholder graphic that arbitrary scraped CDN hosts serve instead of
  the real photo — a real, valid, correctly-sized image, just not a
  product photo. A GD colour-variance detector was built to catch this
  and still missed most of them on a second full run, because JPEG
  compression and antialiased rotated placeholder text manufacture enough
  colour variety to defeat that kind of heuristic — confirmed by
  duplicate-hash analysis on real output, not assumed. Pexels (25,000
  requests/hour, own CDN rather than arbitrary hosts) finished the full
  162-product set in one run, 0 failures, verified clean by the same
  duplicate-hash method plus direct visual inspection across every
  duplicate cluster and a spread of singles: 159 distinct photos, 23 rows
  legitimately sharing a generic category photo where Pexels had nothing
  more specific.

### Changed

- **`database/seeders/` split into `Demo/`, `System/`, and `Stress/`
  subfolders**, namespaced accordingly (`Database\Seeders\Demo\...`,
  `Database\Seeders\System\...`, `Database\Seeders\Stress\...`).
  `DatabaseSeeder` stays at the root — it is Laravel's entry point and
  `migrate:fresh --seed`'s implicit target. Grouping: `Demo/` is every
  seeder that produces presenter-facing demo content plus the two
  reference seeders its fixtures resolve against (`CatalogueReferenceSeeder`,
  `ContentReferenceSeeder`); `System/` is what CI, local, and production all
  need regardless of demo content (`CarrierSeeder`, `PermissionSeeder`,
  `RoleSeeder`, `UserSeeder`); `Stress/` is `StressSeeder` alone, kept
  separate from `Demo/` despite subclassing `DemoOrderSeeder` because it
  produces no narrative content and must never be mistaken for something
  the demo run needs.

  **`db:seed --class=` needs the fully-qualified class name now** — a bare
  basename like `--class=DemoSeeder` no longer resolves once a seeder is
  namespaced under a subfolder; every doc and script has to pass
  `--class="Database\Seeders\Demo\DemoSeeder"` (quoted, since an unquoted
  backslash is a shell escape character). Updated everywhere this was
  found: `seed-the-database.md`, `demo-data.md`, `article-fixture-format.md`,
  `edit-a-role.md`, `troubleshooting.md`, `tech-stack-overview.md`, the
  docblocks inside `DemoSeeder`/`DemoArticleSeeder`/`StressSeeder`
  themselves, and 18 test files (`use Database\Seeders\PermissionSeeder;`
  and siblings) that imported the pre-move classes directly — those were
  silently broken until fixed, since a missing class only surfaces when the
  test file actually runs, not at edit time. `misc/`'s two session briefs
  (gitignored, not shipped) were left as historical record with a note at
  the top rather than rewritten, since they document a plan already
  executed under the old paths.

### Added

- `App\Support\ProtectedSkus` and
  `database/fixtures/reference/protected-skus.json` — a guard against a
  silent data-destruction trap found while planning the transactional
  seeding pass. `TransitionOrderStatus` on `=> Shipped` composes
  `CompleteSale`, which moves stock from reserved to sold and permanently
  drops `current_quantity`, and `CreateOrder` reserves at creation. So any
  seeder that samples order lines at random consumes exactly the SKUs
  `demo-data.md` documents as "out of stock" and "exactly one left" — and
  the failure is invisible: the seeder succeeds, the orders look correct,
  and the presenter's lookup table is quietly wrong.

  The list is JSON rather than a PHP const so it is readable by anything
  that needs it, not only by the one class that must not violate it.
  `assertSelectable()` throws rather than returning false, so a protected
  line cannot be silently skipped into a set smaller than the distribution
  it claims to have written. `floorFor()` carries the `min_order_quantity`
  floors, which are deliberately *not* an exclusion — those products
  belong in seeded orders.

  Verified against a freshly seeded catalogue rather than by reading the
  code: all 19 excluded SKUs resolve to real rows (14 products, 5
  variations), all 5 `min_order_quantity` floors match the database, the
  guard blocks exactly 19 of 219 variations and leaves 200 selectable,
  `assertSelectable()` throws on a protected line and passes an ordinary
  one, and the states being protected are real (`current_quantity` 0 and 1
  respectively, `CLM-0016.is_available` false). A protected variation may
  be blocked through its parent product rather than its own SKU —
  `CLM-0016-STD` is refused because `CLM-0016` is unavailable — so callers
  pass the variation and let the guard do both lookups.

  Not yet exercised by a caller: no seeder consumes it, because
  `DemoOrderSeeder` does not exist yet.

- The transactional demo-data pass: `DemoAddressSeeder` (78 addresses
  across 60 of 100 customers), `DemoEngagementSeeder` (80 newsletter
  subscribers, 25 hand-written contact messages), `DemoOrderSeeder` (140
  orders built by checking out real carts through `CreateOrder` and
  `TransitionOrderStatus`, never fabricated with `Order::factory()`),
  `DemoReviewSeeder` (90 reviews drawn only from delivered orders' own
  purchases), `ContentReferenceSeeder`'s tag vocabulary extended for the
  general-marketplace catalogue, and 24 article fixtures across 4 batches.
  `StressSeeder` for table-size testing, built as a thin subclass of
  `DemoOrderSeeder` reusing its `loadPools()`/`seedOneOrder()` rather than
  a second implementation.

  Verified against the live database, not the seeders' own output: 140/140
  orders on the exact status distribution planned, every `PaymentStatus`
  and `ShipmentStatus` case represented at least once (including `Failed`
  with 2 of 4 recovering to `Paid`, and `PartiallyRefunded` with 2 stacked
  refunds), 19 coupon redemptions including `ONEUSEONLY`'s first-ever one,
  90 reviews on the exact rating distribution with 62 approved / 28
  pending, 24 articles on the exact status distribution. `StressSeeder` run
  at 2000 orders: 1734 succeeded, 266 refused cleanly via
  `RuntimeException` as the ~200-variation selectable pool ran low against
  2000 orders' demand — zero protected-SKU violations and zero inventory
  overdraw (`reserved_quantity > current_quantity`) across the resulting
  1922 total orders, confirming `ProtectedSkus` holds under real load, not
  only the 140-order demo scale it was designed for.

  Two real bugs found only by running against live data, not by reading
  the code: `CreateOrder`'s `$actor` parameter is not an authorization gate
  like every other Action's — it is the checking-out customer, written to
  `orders.user_id` and used to scope `source_address_id`. Passing `null`
  for a registered customer (the pattern correct everywhere else in this
  pass) threw `ModelNotFoundException` on every order using a saved
  address. And a `Refunded` order-status walk that went `Shipped =>
  Returned` directly, skipping `Delivered`, left a COD order with no
  payment ever opened (COD is marked paid on remittance, i.e. at
  `Delivered`, never before) — so a `Refunded` order-status target with no
  refundable payment is a contradiction the walk now avoids by routing
  every path through `Delivered`.

  Not built in this pass: a real Stripe test-mode round trip. No
  checkout-session controller or webhook route exists yet — `RecordPayment`
  and `TransitionPaymentStatus` are called directly, proving those two
  Actions' locking and status-transition rules, but not a real Stripe API
  call. `stripe/stripe-php` is installed; nothing in `app/` calls it yet.

- `docs/reference/schema/demo-data.md` — the presenter-facing index of what
  the seeded catalogue actually contains: the exact SKU for every coverage
  state (out of stock, one left, each discount phase, unavailable, 5+
  variations, ≥90-char name, min-order-quantity, zero-attribute products),
  the two variation-gallery shapes (`PWR-0012` — one image shared by three
  variations; `PWR-0001` — one variation carrying two images) with a
  reproduction query, the coupon table, and what's deliberately absent
  (orders, images, a discount at exactly the boundary). Verified against a
  live seed, not the fixture JSON — every count and SKU on the page was
  read back from the database after `migrate:fresh --seed` plus every demo
  seeder.

  Two demo-data gaps surfaced and were closed while building it: no product
  had a *scheduled* discount despite the coverage matrix requiring one
  (`KIT-0011` now does), and no product had 5+ variations despite the same
  requirement (`CLM-0001` gained a fifth). `PWR-0010`'s ≥90-character name
  was also short by 2 characters — `wc -c` had counted UTF-8 bytes for a
  name containing an em dash, not characters; `mb_strlen`, matching what
  `ValidateFixtures::assertMaxLength()` actually checks, was the correct
  measure and is now what was used to fix it.
- The demo catalogue: 169 products, 219 variations, spanning 122 of the
  173 leaf/branch categories in `catalogue.json` and 33 of 34 brands, in
  `database/fixtures/demo/`. Nine batches (`power-tools`,
  `hand-tools-garden`, `workwear`, `clothing-men`, `clothing-women`,
  `electronics`, `kitchen`, `home`, `sports-beauty-toys`), each its own
  SKU prefix per `SKELETON.md`'s collision scheme.

  Verified rather than assumed: every state in `fixture-format.md`'s
  coverage matrix appears at least once (out of stock, exactly one left,
  discount active/expired/scheduled, 1/3/5+ variations, unavailable
  products, no-image products, a ≥90-character name, `min_order_quantity
  > 1`), plus two variation-gallery shapes not previously called out
  explicitly in the matrix and added there: one product image shared
  across ≥2 variations' galleries (39 instances across the set) and one
  variation carrying ≥2 images in its own gallery (20 instances) — both
  confirmed against the actual `product_image_product_variation` pivot
  after seeding, not only against the JSON shape.

  Demo images referenced under `demo/*.jpg` are not committed — item 11 of
  `fixture-format.md`'s numbered rules states the file does not have to
  exist for a fixture to validate and load. Actual image files, when
  added, belong in `storage/app/public/demo/` (gitignored, already
  reachable at `/storage/demo/...` through the existing `public` disk
  symlink) rather than `public/demo/`, which the `Storage::disk('public')`
  calls `ResolveVariationImage` and `RemoveProductImage` already use would
  not serve.
- `App\Console\Commands\ValidateFixtures::assertLengths()` — see the
  standalone commit; folded in here because the length caps are what the
  demo batches above are validated against.

### Documentation

- **The N+1 rule is now written down** — `CLAUDE.md`'s architecture list,
  `project-conventions.md`, and a new "Eager loading, and the N+1 rule"
  section in `explanation/filament-resources.md`.

  Filament does **no** eager-loading of its own — verified by reading
  `filament/tables`, which contains no `->with()` anywhere. So a
  `TextColumn::make('brand.name')` is one extra query per row and the page
  still renders correctly, which is why it goes unnoticed. Measured on five
  product rows: 6 queries lazy, 2 eager.

  The less obvious half is an accessor that reads a relation:
  `Order::$payment_status` derives from `payment`, so a column showing it
  lazy-loads per row even though the column name contains no dot to hint at
  it. Both are fixed on the table via `modifyQueryUsing()`.

  Records that `preventLazyLoading()` is still deliberately off per ADR-0012,
  and that the rule is enforced by review and query-count tests instead — a
  query-count assertion earning its place only where the relation is hidden,
  since asserting it for a plain dot-notation column would be testing
  Filament's rendering.

  Also lists the ten tables that currently carry a relation column with no
  eager-loading, as one pass worth doing before the demo catalogue makes
  those pages long.

### Changed

- **`orders.payment_status` is derived, not stored.** The column is dropped;
  `Order::$payment_status` now reads through the `payment` relation, falling
  back to `Pending` when no payment row exists.

  It was written exactly once, by `CreateOrder`, and never again — nothing
  else in `app/` ever wrote it — while `payments.status` moved independently
  through `TransitionPaymentStatus`. So the two diverged the moment a payment
  was paid or refunded, and the stale one was what `OrdersTable` and
  `OrderInfolist` displayed and filtered on. Confirmed by walking it: a
  payment reading `paid` left its order reporting `pending`.

  §20 forbids storing `inventories.available()` for exactly this reason, and
  this is the same shape with the same resolution. Not backfilled before
  dropping: the column's values were wrong wherever a payment had moved and
  right only where nothing had happened, so copying them onto `payments`
  would have overwritten correct data with stale data.

  `OrdersTable` eager-loads `payment` in `modifyQueryUsing()` (deriving it
  per row would be one query per row), its filter queries through the
  relation and counts a missing payment as `Pending`, and the column is no
  longer `->sortable()` — there is no `orders` column left to sort by.
  `OrderPaymentStatusTest` covers the derivation, including a structural
  assertion that the column does not exist, so reintroducing it in a later
  migration fails loudly rather than silently shadowing the accessor.

### Added

- Tests for the five previously-untested Actions — `RecordPayment`,
  `TransitionPaymentStatus`, `CreateShipment`, `TransitionShipmentStatus`,
  `CreateProductReview`. 37 feature tests and 7 concurrency tests; every
  guard was deleted and observed failing before being restored, per
  CLAUDE.md's rule that a test never seen red proves nothing.

  Three of the concurrency tests cover money directly. Two concurrent
  partial refunds that individually fit but together exceed the payment:
  without `lockForUpdate()` on `payments` both read `refunded_amount = 0.00`,
  both pass their own cap check, and the payment is refunded past its own
  amount — verified by removing the lock. Its counterpart asserts that two
  refunds which *do* fit together both land and accumulate, so the first
  test cannot pass by the Action simply refusing everything. And two
  simultaneous `CreateShipment` calls on a COD order would otherwise produce
  two consignments, each carrying the full `cod_amount` — the courier
  collecting the total twice on the doorstep.

  `RecordPaymentConcurrencyTest` opens by asserting `payments` has **no**
  `UNIQUE(order_id)`, because the rest of the file only proves the lock while
  that stays true; adding such an index later would otherwise silently turn
  those tests into a test of the index.

  The review race is the odd one out and documented as such: it has no lock
  by design, `UNIQUE(user_id, product_id)` guarantees one row whatever the
  code does, so the count proves nothing. What it proves is *how the loser
  fails* — rewriting the Action as check-then-act keeps the count at 1 and
  still fails, because the loser then gets a raw `QueryException` instead of
  `ReviewNotAllowedException`: a 500 on a review form.

### Fixed

- Two documents still claimed `TransitionOrderStatus` was unbuilt, months
  after it shipped. Root `CLAUDE.md` said *"designed in ADR-0004, **not yet
  built**"* under a non-negotiable architecture rule, and
  `reference/write-rules/order.md` said *"`TransitionOrderStatus` does not
  exist yet"* in its "what does not get written" section. Both now describe
  the Action as built and the only writer of `orders.status` and
  `order_status_histories`.

  The surviving half of each claim was kept rather than deleted with the
  false half: `CreateOrder` really does still land every order at `New`
  regardless of payment method, and nothing calls `TransitionOrderStatus`
  from checkout — because the Stripe and cash-on-delivery first hops
  diverge (`New => AwaitingPayment` against `New => Confirmed`) and
  `CreateOrder` is blind to which applies. That is a deliberate decoupling,
  not a gap, and the correction says so.

  `online-store/.ai/guidelines/project-conventions.md` needed no change —
  the condensed form had always stated the rule correctly. The other four
  pages referencing the Action (`actions.md`, `inventory.md`,
  `concurrency-and-locking.md`, `security-model.md`) were already accurate.

- `FixtureLoader` silently dropped a product's `attributes` field.
  `productColumns()`'s `Arr::except()` stripped it out to build the
  `products` insert, and nothing ever used it afterward — `attribute_product`
  stayed empty for every fixture-loaded product, for every fixture ever
  loaded before this session, invisibly: nothing else in `app/` reads that
  pivot yet, so no test and no panel screen surfaced the gap. Found while
  auditing the 169-product demo catalogue for `demo-data.md` — a
  `whereDoesntHave('attributes')` count that should have matched only the
  genuinely single-SKU products instead matched all 169.

  Fixed with `FixtureLoader::attachAttributes()`, mirroring
  `attachAttributeValues()`'s existing pattern exactly: resolve the fixture's
  attribute slugs against real `attributes.id` rows and
  `syncWithoutDetaching()`. Verified:
  `Product::where('sku','CLM-0001')->first()->attributes()->pluck('slug')`
  now returns `colour, size`; the zero-attribute count correctly dropped to
  48 (matching the products actually authored with `"attributes": []`).
- `AddProductVariation`/`RemoveProductVariation` composing `SetDefaultVariation`
  with `$actor` passed through, rather than `null`. `ProductVariationPolicy`
  gives `create`/`update`/`delete` three separate permissions (unlike
  `ProductImagePolicy`, where they collapse to one `update_product`), so an
  actor holding only `create_product_variation` could create a variation but
  then fail `AuthorizationException` on the automatic first-becomes-default
  promotion, which demands `update_product_variation` — a permission the
  create path never claimed to need. `RecordInventoryMovement`'s own
  docblock states the precedent this should have followed from the start:
  "authorizes nothing — the caller has already authorized what this
  records." Caught by the full Feature suite, not by either Action's own
  test file in isolation — both passed alone because their fixtures granted
  every relevant permission together. Regression test added:
  `AddProductVariationTest`'s "allows an actor holding create_product_variation"
  now asserts the promotion, not just that the create succeeded.
- `ProductResourceTest`'s `'creates a product with a stock row through the
  panel'` — the `Repeater::make('variations')` item in `ProductForm` never
  reached `weight_display_unit`'s own `->default()`, because filling a
  repeater item via Livewire replaces it wholesale rather than merging over
  per-field defaults; a live browser submit always carries the Select's
  value, so the test's omission was under-specifying the form, not a defect
  in it. Same root class of bug as the `is_default` one above: passing
  green in isolation, caught only once the CLAUDE.md-mandated post-change
  gate ran the whole suite.

### Added

- `database/fixtures/reference/catalogue.json` — `CatalogueReferenceSeeder`'s
  vocabulary (categories, brands, attributes) moved out of a PHP const and
  into a document the seeder reads. Categories now nest to **arbitrary
  depth**: the old `slug => [name, children: slug => name]` const could not
  express a third level at all, because its children were plain strings, so
  `clothing > men > tops > t-shirts` was unrepresentable. Verified to depth 4.

  Prompted by a ~100-node marketplace taxonomy needing to replace the 14-node
  hardware tree — and the part most likely to be regenerated in bulk is the
  worst candidate for being a PHP literal, where a generation slip becomes a
  syntax error inside `database/`. The seeder validates as it loads and names
  the offending key on a category missing `slug`/`name` or an attribute with
  an unknown `type`. It adds and updates but never deletes: dropping a
  category with products attached is `DeleteProductCategory`'s guarded
  decision, not a seeder side effect.
- `DemoCustomerSeeder`, `DemoCartSeeder`, `DemoCouponSeeder`,
  `DemoWishlistSeeder` — the four remaining pieces of the demo dataset, none
  of them fixtures. `fixture-format.md` previously documented a four-array
  top-level JSON shape (`users`/`products`/`coupons`/`carts`) that
  `FixtureLoader` never implemented — `loadProduct()` is its only entry
  point, and a file in that shape failed validation with
  `is missing required key [name]` before a row could load. Rather than
  build three more loaders for data that gains nothing from being hand
  authored, these seeders produce it directly: `DemoCustomerSeeder` (100
  factory customers), `DemoCartSeeder` (carts for ~35% of them plus a few
  guest carts, through `TouchCartExpiry` so `expires_at` follows the same
  rule a real cart write does — `null` for a registered customer, `now() +
  guest_ttl_hours` for a guest), `DemoCouponSeeder` (one coupon per required
  state), `DemoWishlistSeeder` (wishlists for ~20% of customers). Doc
  rewritten to match; see its own "What a fixture file actually contains"
  section.

  One coverage state could not be produced: a coupon at
  `total_usage_limit` **reached**. `coupons.times_used` does not exist —
  `RedeemCoupon` counts real `coupon_redemptions` rows instead
  (`reference/actions.md`) — and a redemption needs a real `order_id`
  (`NOT NULL`, cascade-on-delete). Orders are explicitly 0-scope for this
  seed, so faking one just to exhaust a coupon would fabricate order data
  nothing else in the set produces. `ONEUSEONLY` (`total_usage_limit: 1`,
  never redeemed) covers the narrow-limit path only; "reached" belongs to
  whichever session seeds orders.
- `App\Support\ResolveVariationMeasurements` — the weight/dimension
  counterpart to `ResolveVariationPrice`, and the fix for a semantic that
  was documented without being implemented: the new nullable
  `product_variations` measurement columns were specified as
  "null inherits the product's" with nothing anywhere performing that
  inheritance, so `$variation->weight_g` returned null for the common
  inheriting variation. A null weight reaching a courier is a zero-weight
  parcel rather than an error, which is why this is a resolver and not a
  convention.

  Inheritance is **per axis**, correcting an "all three or none" rule
  written into three docs a day earlier. That rule forbade the most
  realistic override there is — a hardcover edition is the same page size
  as its paperback sibling and only thicker — and buying nothing for it,
  since per-axis fallback is exactly what `price` already does. `isShippable()`
  is the one all-or-nothing check, because a courier quote needs the whole
  set even though each figure resolves on its own. 5 tests, all observed
  failing against a broken fallback first.
- `carriers.cod_fee` — BG couriers charge a separate cash-on-delivery
  handling fee, priced per carrier (Econt and Speedy differ), so it lives on
  `carriers` rather than `products` or `orders`. Not yet folded into an
  order total: `CreateOrder` hardcodes `shipping = '0.00'` until carrier
  selection (`CalculateDeliveryPrice`, slice 8) exists to read it from.
  `CarrierSeeder` seeds both at placeholder figures, not a published tariff.
- `App\Support\Money` — a readonly decimal value object, and the only place
  `bc*` is now called. Every money column is `decimal(n,2)`, so `SCALE` is
  internal and a caller never picks one: `bcadd($a, $b)` without a scale
  defaults to 0 and turns 189.90 into 189, which was reachable at any of the
  30 call sites this replaces across 6 files.

  Two methods carry the calculations whose precision is not obvious.
  `percentageOf()` is VAT extraction — prices are stored gross, so VAT is
  `amount * rate / (100 + rate)`, not `/ 100`, and getting it backwards
  overstates VAT on every line without failing. `shareOf()` is proportional
  allocation for splitting a discount across matched lines. Both run their
  intermediate at double scale and round once, because rounding each step
  compounds across a multi-line cart.

  Deliberately **not** currency-aware: `App\Enums\Currency` exists and orders
  and payments snapshot it, but the catalogue is single-currency and a
  currency field here would imply mixed-currency arithmetic is guarded when
  it is not. `reference/schema/open-schema-questions.md` #2 has what real
  multi-currency needs; the guard belongs here when it arrives.

  20 unit tests, no database. `CalculateCartTotals` (15) and
  `CalculateCouponDiscount` (17) verified unchanged after migration — the
  latter includes the proportional-allocation cases.
- `App\Filament\Concerns\ConvertsMeasurementInput`, shared by `CreateProduct`
  and `EditProduct`. `ProductForm` now asks for weight and the three
  dimensions in a chosen unit via `*_input` fields marked `dehydrated(false)`;
  this converts them to the canonical `weight_g` and `*_mm` columns on save.
  Shared rather than duplicated because a conversion that disagreed between
  the two pages would store different numbers for the same typed input.
- `product_variations.length_mm`/`width_mm`/`height_mm`, mirroring `price`'s
  existing null-inherits-the-product pattern: `null` on a variation means
  "same as the product", set only when a variation genuinely differs (a
  book's hardcover edition weighing more than its paperback sibling). No
  per-variation `dimension_display_unit` — a variation's dimensions are
  entered and shown in the *product's* display unit, never its own, since
  splitting the display convention across siblings buys nothing. Weight
  keeps its own per-variation `weight_display_unit`, because weight (unlike
  the display convention for dimensions) can genuinely differ enough between
  variations to warrant it.
- `product_variations.is_default` plus `App\Actions\Catalogue\SetDefaultVariation`,
  mirroring `SetMainProductImage`/`is_main` exactly: one `UPDATE ... SET
  is_default = (id = N)` statement, no lock needed since it's a blind write
  rather than a check-then-act. `AddProductVariation` auto-promotes a
  product's first variation to default the same way `AddProductImage`
  promotes a product's first image to main, and accepts `is_default: true`
  on a later variation to override it. `RemoveProductVariation` hands the
  flag to a live sibling if the removed variation held it, the same
  successor-promotion `RemoveProductImage` does for `is_main` — a product
  never ends up with variations and no default.
- `ProductVariationsRelationManager` — table gained an `is_default` badge
  column and a "Make default" row action (star icon, hidden once a
  variation is already default); the create/edit form gained weight and
  dimension inputs in the product's display unit, converted to canonical
  columns via `ConvertsMeasurementInput` before the Action runs.

### Removed

- `products.weight`, `products.dimensions`, `product_variations.weight` —
  the pre-structured free-text/decimal columns these superseded weeks ago,
  finally dropped now that every remaining reference to them (models,
  factories, fixtures) was confirmed migrated to `weight_g`/`*_mm`. Kept
  around "for one day" past the original migration pending that
  verification; the day came.

- The store is **Amazoff**. `APP_NAME` set in `.env` and `.env.example`;
  `logo.png`/`logo2.png` and `favicon.ico`/`favicon2.ico` swapped so the
  chosen pair is the one the app serves. `logo3.png` and `favicon3.ico` are
  untouched alternates.
- `App\Enums\Currency` (EUR, BGN) with `symbol()` and `minorUnitDigits()`.
  An enum rather than a lookup table, and not the roles exception: adding a
  currency needs a rounding rule, a symbol and a decimal count, all of which
  are code. `minorUnitDigits()` states the assumption that 2 is not universal
  — JPY is 0, and `decimal:2` arithmetic against a zero-decimal currency
  silently multiplies by 100. Nothing depends on it yet.
- `App\Enums\LengthUnit` and `App\Enums\WeightUnit`, with conversion in
  both directions, plus `2026_08_23_120000_add_structured_dimensions_and_weight`:
  `products.length_mm`/`width_mm`/`height_mm`/`dimension_unit`, and
  `weight_g`/`weight_unit` on both `products` and `product_variations`.
  Storage is canonical (whole millimetres, whole grams) with the entry unit
  stored beside it so the panel shows back what was typed; default cm and kg.

  Replaces free-text `dimensions` and `decimal(8,2)` kilogram `weight`.
  `open-schema-questions.md` #4 argued it: a courier prices on volumetric
  weight, needing 3 numbers, and parsing `"24 x 8 x 21 cm"` plus the
  `24x8x21cm` and `240 x 80 x 210 mm` variants a generator will produce is a
  bug found at API-call time rather than data entry. Verified: 5 g now stores
  as 5 g, where `decimal(8,2)` kg rounded it to 10 g.

  The superseded columns are **kept, not dropped** — dropping a merged column
  alongside its replacement leaves no way to verify a backfill. No backfill
  was needed (no production data, fixtures not yet authored), which is why
  this was cheap now. `open-schema-questions.md` #3 tracks the removal.
- `App\Actions\Cart\TouchCartExpiry` and `config/cart.php` — the missing
  half of `ExpireCarts`, which had nothing to act on because nothing ever
  wrote `carts.expires_at`. Guest carts expire 24 hours after the last write;
  a registered customer's cart never expires and has the column cleared.
  Called on every cart write so the window slides.

  `MergeGuestCart` now calls it inside its transaction, and that is the
  load-bearing case: without it a merged cart keeps the guest expiry and
  `carts:expire` deletes a registered customer's cart a day later.
- `docs/reference/schema/open-schema-questions.md` — deferred schema decisions, each
  with today's state, options with costs, a recommendation, and the trigger
  that would force revisiting. Linked from `CLAUDE.md`'s router.

- File upload validation on both image fields in the panel — product
  images (`ProductImagesRelationManager`) and article images (`ArticleForm`)
  — closing §37 standard 18's type and size half. `ProductImage` and
  `Article` each gained named constants (`ACCEPTED_MIME_TYPES`/
  `IMAGE_ACCEPTED_MIME_TYPES`, a JPEG/PNG/WebP allow-list; `MAX_SIZE_KB`;
  `MIN_WIDTH_PX`/`MIN_HEIGHT_PX`, a 400px floor against an accidental
  thumbnail upload) so both fields reference one source rather than
  duplicating literals. `->image()`'s own MIME sniff is unchanged;
  `->acceptedFileTypes()` narrows it from "any image" to the allow-list, and
  `Illuminate\Validation\Rules\Dimensions` enforces the floor — a
  validation rule, not a `FileUpload` method, applied through `->rules()`
  the same way price fields already apply `decimal:0,2`. `->imageEditor()`
  added to both fields too, optional crop/rotate for an admin who wants it.
  Storage method (§37 standard 18's third leg) was already met — both
  fields go through `ProductImage::DISK`/`Article::IMAGE_DIRECTORY`, named
  constants rather than literals, unchanged by this entry. Standard 19
  (unique generated filenames) stays open below — Filament's default
  upload-naming path was not traced to an actual stored filename, so it is
  recorded honestly as unverified rather than assumed.

  Caught by Larastan, not by review: the first version called
  `Dimensions::make()`, which does not exist on this Laravel version's
  `Dimensions` class — it has a plain constructor, no static factory.
  `php -l` and a first read both passed; `phpstan analyse --memory-limit=1G`
  did not, with `Call to an undefined static method
  Illuminate\Validation\Rules\Dimensions::make()` on both call sites.
  Fixed to `(new Dimensions())->minWidth(...)->minHeight(...)` and
  reconfirmed clean. Recorded in `troubleshooting.md`'s new entry as the
  general case: verify a fluent builder's actual API against the installed
  version before assuming a common Laravel idiom applies unchanged.
- `docs/how-to/troubleshooting.md` — two Windows/Docker-specific traps hit
  this session, in one entry: stopping a background test run through the
  harness kills the shell wrapper but not a child `pest` process it
  spawned, which then keeps racing every later command against the same
  MySQL container (`docker compose top app` is the reliable check;
  `docker compose exec app kill` doesn't exist on this image, `posix_kill`
  from a PHP one-liner does reach it); and Git Bash silently rewrites a
  bare `/tmp/...` argument to a Windows path before `docker compose exec`
  ever sees it, producing a Linux-shaped "No such file" error for a file
  that exists exactly where it should — `MSYS_NO_PATHCONV=1` is the fix.
  Both cost real time this session before the actual cause was found;
  two coverage runs and a Feature-suite run were re-run clean afterward to
  confirm neither had left a false result behind.
- `CLAUDE.md`'s "Working style" — when a test earns its place. Written
  after being asked whether the file-validation constraints above needed
  their own Pest coverage; they don't, and the rule states why: a test that
  reasserts Filament's or Laravel's own machinery works (does
  `->acceptedFileTypes()` reject a bad MIME type, does `Dimensions` reject
  a too-small image) proves the framework, which is already proven
  upstream, for a flaky, fixture-heavy cost. `ProductResourceTest.php`'s
  `'refuses a product with no variations'` already draws the line
  correctly — it uses Filament's own form-testing machinery, but what it
  proves is `ProductRequiresVariationException`'s territory, a domain rule.
  Mirrored into `.ai/guidelines/project-conventions.md`'s Testing section
  per this file's own rule to keep the two in agreement.

- Variation image galleries — `product_image_product_variation`, a
  many-to-many between a variation and its product's own `product_images`,
  with a `position` column and a composite primary key over the pair. A
  variation shows some of the product's photographs in an order it chooses;
  one photograph can sit at a different position in several galleries, so a
  product shot in five colours across eight sizes is thirty image rows rather
  than two hundred and forty. `product_images` is untouched — still
  product-owned, `product_id` still NOT NULL, the whole
  `AddProductImage`/`SetMainProductImage`/`RemoveProductImage` triad and its
  one-main invariant unchanged. ADR-0013 has the alternatives, including the
  attribute-value swatch model that would have been better on write and worse
  on read.
- `app/Actions/Catalogue/SetVariationImages.php` — 1 Action owning the whole
  ordered set, rather than an attach/detach/reorder triad. The contested state
  is the ordered list, so it gets one owner, the way `SetMainProductImage`
  owns "exactly one main image" for a product. Three anticipated races collapse
  into one: adding while another administrator removes, reordering while
  another detaches, and two simultaneous reorders are the same operation —
  two complete sets, serialised by the `products` lock, the later winning
  wholesale. Position renumbering disappears with them, because nothing ever
  writes a partial set. Verified: order preserved, duplicates collapsed to
  their first position, empty list clears, a cross-product image refused with
  `ImageNotOnProductException`, a variation soft-deleted since page load
  refused with `RemovedFromCatalogueException`.
- `app/Support/ResolveVariationImage.php` — gallery position 1, else the
  product's main image, else null. Computed, never stored, the same shape as
  `ResolveVariationPrice`.
- `tests/Concurrency/SetVariationImagesConcurrencyTest.php`, plus
  `set-variation-images` and `remove-image` arms on `race:worker` and an
  `idsFrom()` accessor for variable-length id lists. Deletion-proofed and it
  actually went red: removing the `products` lock fails the atomicity
  assertion on 4 of 4 attempts, with the surviving gallery containing rows
  from *both* submissions — `sync()` issues its detach and its attaches as
  separate statements, and two transactions interleave between them. Worth
  recording next to `DeleteProductCategoryConcurrencyTest`, where fourteen
  attempts never forced the equivalent failure; this window is wide enough to
  hit every time.
- A gallery modal on `ProductVariationsRelationManager`, as a row action
  rather than the nested relation manager the draft plan assumed — Filament
  relation managers do not nest. It happens to fit the Action: the modal
  submits the whole set, and the multi-select's selection order becomes
  `position`.
- `docs/adr/0013-variation-image-ownership.md`,
  `docs/reference/write-rules/product-variation-images.md`, and
  `docs/explanation/product-variability.md`. The last also records which
  large-catalogue techniques this schema already uses, which one is worth
  designing toward (a flattened listing projection, so Blade never joins 5
  tables per row), and which two are deliberately not done.

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
- `brianium/paratest` as a dev dependency, plus a wildcard grant in
  `docker/mysql/init/01-test-database.sh` for the per-worker databases
  Laravel creates. `pest --parallel --processes=4 --testsuite=Feature` runs
  472 tests in 260s against 474s sequential. Two measured findings recorded
  in `run-the-tests.md`: `--processes=12` (this machine's `nproc`) is
  *slower* at 338–361s, because every worker re-runs `migrate:fresh`
  including the ~55s schema load and twelve of them contend on one MySQL
  container; and `Concurrency` must never run under `--parallel` — 21 of 33
  tests fail, since Laravel only switches a test case onto its own per-worker
  database when it uses `RefreshDatabase` or a sibling trait, which
  `tests/Pest.php` deliberately does not apply there.
- `app/Actions/Cart/ExpireCarts.php` and `app/Console/Commands/ExpireCarts.php`
  — deletes carts past `expires_at`, excluding any already referenced by
  `orders.cart_id`, scheduled `->daily()` in `routes/console.php`. The first
  scheduled command in the project. Inert today by design: nothing in `app/`
  writes `expires_at` yet, because the TTL policy (guest carts expire after
  about a month; a registered customer's cart does not expire — the checkout
  stage is what expires for them) is not built.
- `docs/reference/console-commands.md` — every custom Artisan command, what
  invokes it, and why a command is a caller rather than a place a rule lives.
  Also records that nothing runs `schedule:run` locally, so a scheduled
  command never fires on its own in Docker.
- `app/Console/Commands/RaceWorker.php` and
  `tests/Concurrency/RaceHelper.php` — one `race:worker` Artisan command
  replaces the PHP nowdoc every concurrency test used to write to
  `base_path()`, spawn, and `@unlink()`. All twelve race files now describe
  a race as a job list (`action`, `ids`, `args`, optional `rendezvous`) and
  call `runRaceWorkers()`. `concurrency-and-locking.md`'s "Why the worker is
  generated, not committed" had already recorded the duplicated bootstrap as
  a cost and named this refactor as an agreed follow-up; this is it. Roughly
  700 lines of duplicated boilerplate removed, and the suite got faster as a
  side effect —
  518s against a ~695s baseline, because Artisan's bootstrap beats a
  hand-rolled `require bootstrap/app.php` per worker. Behaviour preserved:
  all 33 tests pass, and `ReserveStockConcurrencyTest` was re-deletion-proofed
  against the new harness (lock removed, `QueryException` instead of the
  clean refusal, exactly as before). The rendezvous ready-flags moved to
  `storage/framework/testing/`, which Laravel already gitignores — a worker
  killed between planting its flag and unlinking it used to leave an
  untracked file in the project root.
- `tests/Pest.php`: `Feature` now uses `LazilyRefreshDatabase` instead of
  `RefreshDatabase` — same isolation guarantee, migrates only on first DB
  touch. Measured back to back on this suite: 683s → 617s, 479/479 tests
  unchanged. Verified structurally, not just measured: `LazilyRefreshDatabase`
  `use`s `RefreshDatabase` internally, so `class_uses_recursive()` still
  resolves it for Laravel's per-worker test-database switching —
  `pest --parallel --testsuite=Feature` is unaffected.
- `docs/adr/0012-laravel-boost.md` and `laravel/boost` (dev-only) — MCP
  server (schema/query/log tools plus semantic search over
  Laravel/Filament/Pest docs), guidelines, and skills for AI-assisted
  development. `online-store/.ai/guidelines/project-conventions.md` is the
  hand-written, committed source; `online-store/CLAUDE.md`, `boost.json`,
  and `.claude/skills/` are generated and gitignored, rebuilt by
  `php artisan boost:install`. `.mcp.json` points the MCP entry through
  `docker compose exec app`, since this project's `vendor/` only exists
  inside the container. Root `CLAUDE.md` now says explicitly that it is
  hand-written and points at the generated file rather than being confused
  for it.

  ADR-0012 audits Boost's bundled guidance against this project's actual
  architecture rather than accepting it wholesale. Three real conflicts,
  all overridden in `.ai/guidelines/project-conventions.md`: "only create
  documentation files if requested" (this project's docs are part of the
  work, not an extra); "always use constructor injection, avoid `app()`"
  (every Action in this codebase is resolved at the call site, by design —
  checked against all 30+ existing Actions, not assumed); "code payment
  gateways to an interface" (ADR-0001 decided the opposite, by name: two
  courier implementations justify `App\Contracts`, one Stripe
  implementation does not). Also records, with source-level evidence, why
  `Illuminate\Concurrency\ProcessDriver` doesn't replace `race:worker` —
  no barrier, no rendezvous, and no exposed way to pass per-task `DB_*`
  environment at all.

  One real, applied finding from the audit beyond the ADR itself:
  `carts:expire`'s schedule entry gained `->withoutOverlapping()`, per a
  Boost scheduling rule naming a genuine gap in what was already there.
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
- `HasColor` on the 7 enums where a badge colour carries meaning —
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
- 20 Policy classes — one per resource the permission catalogue names,
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
  transitioning 1 order at once, closing the gap
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
    asserting 2 orders to asserting 1 order and a clean refusal for the
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

- `weight_unit` -> `weight_display_unit` and `dimension_unit` ->
  `dimension_display_unit`. The old names claimed something false: `weight_g`
  is **always** grams, and `weight_unit` beside it reads as "the unit this
  value is in", which would make `weight_g = 1600, weight_unit = kg` mean
  1600 kg. It means 1600 grams, displayed as 1.6 kg. Storage is canonical,
  display is remembered, and the names now say so. Free to rename: nothing
  outside the model declarations read either column.
- `ProductForm` no longer edits the superseded `weight` decimal or the
  free-text `dimensions`. Those columns still exist —
  `reference/schema/open-schema-questions.md` #3 tracks their removal — but
  the panel and the fixture format now agree on which columns matter, which
  they did not for the few hours between the two changes.
- `products.is_available` is confirmed **not** a derived OR of its variations,
  and `write-rules/product.md` now says so. A product may be available while
  every variation is not: §6 gives the merchandiser a switch, and
  visible-but-unbuyable is how a shop signals "coming back". The consequence
  a storefront query must carry is recorded there — an "in stock" filter needs
  the product flag **and** a `whereHas` on variation availability, because the
  flag alone shows products with nothing to buy.
- Review eligibility needed no change: `CreateProductReview` already requires
  a delivered order line for the product, `ProductReviewPolicy::create()`
  returns false outright, and `ProductReviewResource` has no create page — so
  the Action is the only path and it already enforces §24. Recorded rather
  than re-implemented.
- **Dropped `product_variations.image_id`**, the single optional pointer at one
  of the product's images that the gallery replaces. Two columns answering one
  question forces a resolver to invent a precedence rule nothing enforces on
  write. Removed in a new migration, per the append-only rule.

  Three things went with it, each a simplification. `RemoveProductImage`'s
  in-use guard and `ProductImageInUseException`: they converted one specific
  error 1451 into an actionable message, and that 1451 is now unreachable —
  the only remaining reference to `product_images` from outside its own
  product is the pivot, which cascades. Removing an image now takes it out of
  every gallery it was in rather than being refused. `ForceDeleteProduct`'s
  ordering constraint between variations and images, now only a comment. And
  `ProductVariationFactory`'s `image_id`, which called `ProductImage::factory()`
  and therefore attached an image belonging to a *second, unrelated product* to
  every variation it built — `tests/Pest.php` already worked around it with an
  explicit `image_id => null` and a comment saying exactly that. The pivot
  cannot express that mistake; `SetVariationImages` refuses it by name.

  One test was deleted rather than rewritten: "keeps the file when the removal
  is refused", which proved `RemoveProductImage` leaves the file on disk when
  it refuses. The Action has no refusal left, so nothing can reach the branch.
  The property is still real and still commented in the Action; what is gone
  is a way to exercise it.
- The gallery's ordering column is `position`, not `sort_order`, though
  `product_images` already has a `sort_order` — because it already has one.
  The first test written against the pivot failed with `SQLSTATE[23000] ...
  Column 'sort_order' in field list is ambiguous` on a query joining both.
  Renamed rather than qualified at every call site; the migration docblock
  records the failure.

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
- `AssociateAction` and `DissociateAction` removed from all 3 product
  relation managers. `--generate` scaffolds them, but `product_id` is NOT
  NULL on all three child tables, so no row is ever unattached and
  "associate" could only mean reassigning another product's image, spec, or
  variation to this one. Nothing in §6–7 asks for that. They also bypass
  policies entirely — Filament checks only `isReadOnly()` for them — so
  gating rather than removing would have needed a second mechanism.

### Fixed

- Three asset-pipeline failures, all silent, all found only by looking at
  the rendered page. Each has a full entry in
  `docs/how-to/troubleshooting.md`; the short version:

  Tailwind was scanning the **compiled Blade cache**, not Blade source.
  Tailwind 4 anchors automatic source detection at the git root, `.git` sits
  one level above `online-store/`, and the container mounts only
  `online-store/` — so detection collapsed to the two `@source` lines the
  starter kit shipped, one of which is `storage/framework/views`. A class
  therefore existed in the stylesheet only if some page carrying it had
  already been rendered and the cache had not been cleared since, which made
  `php artisan view:clear` actively break working styles. The failure is
  partial rather than total and that is what made it expensive: `grid-cols-2`
  compiled while `lg:grid-cols-3` beside it in the same attribute did not, so
  it read as bad markup for far longer than it should have. Fixed by scanning
  `../views/**/*.blade.php` and `../../app/Livewire/**/*.php`; 16 media
  queries compile now where there had been none.

  Vite wrote `http://0.0.0.0:5173` into `public/hot` — a bind-all address,
  meaningful to a listening socket and meaningless to a browser, so every
  asset request failed at the network layer with nothing in the PHP log.
  `curl` from the host succeeded throughout, which is what made it look like
  the assets were fine. Fixed with `server.origin` and `hmr.host`.

  `Vite::fonts()` was never called. `@vite([...])` does not inject the font
  manifest, so `public/fonts-manifest.dev.json` was generated correctly and
  then referenced by nothing, and Instrument Sans silently fell back to the
  system font. Fixed in the layout head.

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
  6 resources.
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
- `App\Models\Order` had no `@property` docblock naming its 3 enum-cast
  columns, so Larastan inferred `status`/`payment_status`/`payment_method` as
  raw DB-enum string unions instead of `OrderStatus`/`PaymentStatus`/
  `PaymentMethod` the moment `TransitionOrderStatus` read one back and called
  an enum method on it — `troubleshooting.md`'s "Larastan reports an enum
  comparison as always false" entry had already named `Order` as "the next
  likely case" once this Action existed. Added the three annotations,
  matching `Coupon`'s existing precedent.
- `docs/explanation/tech-stack-overview.md` — said "Nothing exists yet for
  `Product` or `Order`" and "no Actions" under Filament resources, both
  several slices stale (12 resources and 24 Actions exist). Corrected in
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
- §37 standard 19 (unique generated filenames on upload). Filament's default
  naming path was not traced to an actual stored filename against this
  version — no seeded or fixture data exercises a real upload, only
  synthetic paths. Verify by uploading through the panel and reading back
  `product_images.path`, then close the standard or add a
  `getUploadedFileNameForStorageUsing()` callback if the default collides.
- Whether Filament 4.12.6's `Repeater` with `->reorderableWithDragAndDrop()`
  actually preserves array submission order the way `SetVariationImages`
  assumes, and whether the `ViewField` thumbnail partial re-renders live
  against its sibling `Select` inside a modal nested in a relation
  manager's row action — built and statically verified, never opened in a
  browser. Same open item for `->rules([(new Dimensions())...])`: Larastan
  now confirms it type-checks, but nobody has uploaded an undersized image
  through the actual form to confirm Filament surfaces the rejection.
- The §11 discount window is implemented twice: `ResolveVariationPrice::
  windowActive()`, which owns it, and `ProductList::discountIsActive()`,
  which duplicates it. A card can therefore advertise a sale price the cart
  refuses to honour. The catalogue cannot simply call the existing resolver
  because that one resolves a *variation* and a card renders a *product*;
  the fix is a `ResolveProductPrice` in `app/Support/` with `windowActive()`
  moved into it and `ResolveVariationPrice` calling through. Owed on the
  product detail page, which needs the same answer at both levels.
- `Catalogue\ProductList` has no tests. Storefront reads are not Actions and
  so fall outside the Action suite by design (ADR-0014); they need
  `Livewire::test(...)` feature tests, a shape this project has not written
  yet. The sort allow-list is the first thing that warrants one, being the
  guard on attacker-controlled input.
- Catalogue search is `LIKE '%term%'` — unindexable, and it matches
  mid-word. Named as a placeholder in ADR-0014 rather than a design;
  full-text or Scout is a decision to make when search quality is the work,
  not underneath the first catalogue page.
- Per-variation product images are a fixture gap, not a code gap. The detail
  page swaps the gallery when a variation is selected and leads with that
  variation's own photographs — verified on `PWR-0001`, where picking Red
  changes the main image to `pwr0001-side.jpg`. But only one product in the
  demo catalogue has variations whose *leading* image differs: 142 of 169
  products carry a single image, and the remaining multi-variation products
  point every colourway at the same file. Selecting a colour therefore looks
  like it does nothing on almost every product, while doing exactly the right
  thing. Closing this means authoring per-colour images into
  `database/fixtures/demo/*.json` and fetching them with `demo:fetch-images`,
  which is fixture work rather than component work.
- `App\Support\ResolveVariationImage` has a full test suite and no production
  caller. It answers "which single image represents this variation", which is
  what a cart line, an order line, a wishlist row, or a listing thumbnail
  needs — the detail page is the one screen that wants the whole ordered set,
  so it deliberately does not use it. Not dead code, but ahead of its
  callers; the cart page is where it should land.
