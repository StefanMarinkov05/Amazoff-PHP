# How to seed the database

Which seeder to run for which situation, and how to author the demo
content. ADR-0003 is why there are several rather than one;
`reference/schema/fixture-format.md` and `reference/schema/article-fixture-format.md` are
the two JSON shapes.

## The seeders

Seeder classes live under three subfolders of `database/seeders/` —
`Demo/`, `System/`, and `Stress/` — grouped by what they're for, not
alphabetically. `db:seed --class=` needs the fully-qualified class name
once a seeder is namespaced under a subfolder; a bare basename does not
resolve. The **Class** column below is the full `--class=` value each
command needs — copy it exactly, quotes included, since an unquoted
backslash is a shell escape character.

| Seeder | Class | Runs where | Contents |
|---|---|---|---|
| `DatabaseSeeder` | *(root — `php artisan db:seed`, no `--class`)* | CI, local, production | Permissions, roles, carriers, staff accounts |
| `DemoSeeder` | `Database\Seeders\Demo\DemoSeeder` | Local, deployed demo | Committed catalogue fixtures |
| `DemoArticleSeeder` | `Database\Seeders\Demo\DemoArticleSeeder` | Local, deployed demo | Committed article fixtures |
| `DemoCustomerSeeder` | `Database\Seeders\Demo\DemoCustomerSeeder` | Local, deployed demo | 100 factory customers, no role |
| `DemoAddressSeeder` | `Database\Seeders\Demo\DemoAddressSeeder` | Local, deployed demo | 78 saved addresses across 60 of 100 customers |
| `DemoEngagementSeeder` | `Database\Seeders\Demo\DemoEngagementSeeder` | Local, deployed demo | 80 newsletter subscribers, 25 contact messages |
| `DemoCartSeeder` | `Database\Seeders\Demo\DemoCartSeeder` | Local, deployed demo | Carts for ~35% of customers plus a few guest carts |
| `DemoCouponSeeder` | `Database\Seeders\Demo\DemoCouponSeeder` | Local, deployed demo | 6 coupons, one per required state |
| `DemoWishlistSeeder` | `Database\Seeders\Demo\DemoWishlistSeeder` | Local, deployed demo | Wishlists for ~20% of customers |
| `DemoOrderSeeder` | `Database\Seeders\Demo\DemoOrderSeeder` | Local, deployed demo | 140 orders, checked out through real carts and walked through `TransitionOrderStatus`, on a fixed status/payment/shipment distribution |
| `DemoReviewSeeder` | `Database\Seeders\Demo\DemoReviewSeeder` | Local, deployed demo | 90 reviews drawn from `DemoOrderSeeder`'s delivered orders |
| `DemoShowcaseOrderSeeder` | `Database\Seeders\Demo\DemoShowcaseOrderSeeder` | Local, deployed demo | Labels 13 real products for the staff-only "Demo order" catalogue sort — `docs/reference/demo-showcase-order.md`. Must run after `DemoSeeder` and `DemoReviewSeeder` (case 6 needs real reviews already attached) |
| `CatalogueReferenceSeeder` | `Database\Seeders\Demo\CatalogueReferenceSeeder` | Local, deployed demo | Catalogue lookup rows — categories, brands, attributes |
| `ContentReferenceSeeder` | `Database\Seeders\Demo\ContentReferenceSeeder` | Local, deployed demo | Article lookup rows — categories, tags |
| `CarrierSeeder` | `Database\Seeders\System\CarrierSeeder` | CI, local, production | Econt and Speedy |
| `PermissionSeeder` | `Database\Seeders\System\PermissionSeeder` | CI, local, production | The permission catalogue |
| `RoleSeeder` | `Database\Seeders\System\RoleSeeder` | CI, local, production | The three staff roles and what each holds |
| `UserSeeder` | `Database\Seeders\System\UserSeeder` | Local, deployed demo (gated non-production) | One account per role, plus a plain customer |
| `StressSeeder` | `Database\Seeders\Stress\StressSeeder` | Local only | 2000 (default) additional orders via the same engine as `DemoOrderSeeder`, for query-plan/pagination testing — never in CI, never presented |
| `CatalogueStressSeeder` | `Database\Seeders\Stress\CatalogueStressSeeder` | Local only | 5000 (default) additional products, each with 1 variation, 1 inventory row, and 1 shared-placeholder image row, for catalogue-scale browsing/pagination/search testing — never in CI, never presented |

Example: `docker compose exec app php artisan db:seed --class="Database\Seeders\Demo\DemoSeeder"`.

Every `Demo*` seeder below `DemoArticleSeeder` except `DemoOrderSeeder` and
`DemoReviewSeeder` is **not a fixture** — no JSON shape, nothing for an LLM
to author. `reference/schema/fixture-format.md`'s "What a fixture file
actually contains" section is why: customers, addresses, carts, coupons,
newsletter subscribers, contact messages, and wishlists are pure state,
which a seeder's own distribution states more reliably than a hand-written
document restates it. `DemoOrderSeeder` and `DemoReviewSeeder` are also not
fixtures, for the stronger reason ADR-0003 gives below: transactional
history is produced by running the Actions that produce it in real use,
never authored as JSON. They all read the catalogue and customers already
loaded, so they run after both — see "Local, the full demo" below for the
exact order and why it matters past alphabetical.

`DatabaseSeeder` is the only one wired into `migrate:fresh --seed`. The demo
seeders are explicit, because CI wants the smallest fixture that exercises
the code and the demo content is neither small nor fast.

Two reference seeders sit behind them — `CatalogueReferenceSeeder` for
categories, brands and attributes, `ContentReferenceSeeder` for article
categories and tags. Both are the lookup rows fixtures resolve slugs
against.

## Everyday: a working local database

```bash
docker compose exec app php artisan migrate:fresh --seed
```

Gives permissions, the three staff roles, both carriers, and one account per
role. No catalogue — nothing to browse, which is fine for running tests and
wrong for looking at the panel.

## Local with a catalogue

```bash
docker compose exec app php artisan migrate:fresh --seed
docker compose exec app php artisan db:seed --class="Database\Seeders\Demo\DemoSeeder"
```

`DemoSeeder` calls `CatalogueReferenceSeeder` itself, so categories, brands,
and attributes exist before any fixture resolves a slug against them. It then
loads every `.json` under `database/fixtures/demo/`.

If that directory is empty it says so and exits cleanly rather than
pretending to have worked.

## Local with articles too

Articles reference products by slug, so the catalogue has to load first:

```bash
docker compose exec app php artisan migrate:fresh --seed
docker compose exec app php artisan db:seed --class="Database\Seeders\Demo\CatalogueReferenceSeeder"
docker compose exec app php artisan db:seed --class="Database\Seeders\Demo\ContentReferenceSeeder"
docker compose exec app php artisan db:seed --class="Database\Seeders\Demo\DemoSeeder"
docker compose exec app php artisan db:seed --class="Database\Seeders\Demo\DemoArticleSeeder"
```

`ContentReferenceSeeder` writes the article categories and tags that article
fixtures resolve against — the content-side counterpart to
`CatalogueReferenceSeeder`. `DemoArticleSeeder` deliberately does **not**
call it, unlike `DemoSeeder` calling its catalogue counterpart: articles also
reference products, so the ordering between the two demo seeders already has
to be deliberate, and hiding one call inside the other would obscure that.

`reference/schema/article-fixture-format.md` is the article document shape.

## What each seeder does and does not write

`CatalogueReferenceSeeder` writes the lookup rows fixtures reference by slug —
categories, brands, and attributes with their values. It is **not** called by
`DatabaseSeeder` — ADR-0003 says a production catalogue is entered through the
panel, never seeded, and these rows are catalogue content rather than the
reference data the application cannot boot without.

Its vocabulary lives in
**`online-store/database/fixtures/reference/catalogue.json`**, not in the
seeder. That file is the single source of truth for every slug a product
fixture may reference, and the seeder is only the loader for it. Categories
nest to **arbitrary depth** — the seeder recurses, so
`clothing > men > tops > t-shirts` is expressible; the shape is a list of
`{slug, name, children?}` nodes.

It was a PHP const until a real marketplace taxonomy (~100 nodes, three or
four levels) had to fit in it: the old `slug => [name, children: slug => name]`
shape could not express a third level at all, because its children were
strings. Moving it to JSON also means the part most likely to be regenerated
in bulk cannot introduce a PHP syntax error into `database/`.

The seeder **adds and updates but never deletes** — a category dropped from
the JSON keeps its row, because deleting one with products attached is
`DeleteProductCategory`'s guarded decision, not a seeder's side effect. It
fails loudly, naming the offending key, on a category missing `slug`/`name` or
an attribute with an unknown `type`.

`CarrierSeeder` **is** called by `DatabaseSeeder`, in production too. Econt
and Speedy are not demo content: delivery pricing cannot resolve a carrier
that has no row, so §15 and §16 do not work without them.

`DemoAddressSeeder`, `DemoEngagementSeeder`, `DemoCartSeeder`,
`DemoCouponSeeder`, and `DemoWishlistSeeder` write their rows directly —
none of these carries an invariant a seeder writing plain Eloquent rows can
violate. `DemoOrderSeeder` and `DemoReviewSeeder` are different: ADR-0003
is explicit that transactional data is produced by running the Actions
that produce it in real use, so a seeded order is reachable, and
explicable, the same way a real one is. `DemoOrderSeeder` builds a real
cart per order and checks it out through `CreateOrder`, then walks it
through `TransitionOrderStatus`, `RecordPayment`,
`TransitionPaymentStatus`, `CreateShipment`, and
`TransitionShipmentStatus` exactly as a real order would be — never
`Order::factory()`. `DemoReviewSeeder` calls `CreateProductReview` and
`ApproveProductReview` against real (reviewer, product) pairs drawn from
those delivered orders' own line items. There is no JSON shape for either
on purpose.

`App\Support\ProtectedSkus`
(`database/fixtures/reference/protected-skus.json`) is consulted by both
`DemoOrderSeeder` and `StressSeeder` before any line is added to a cart —
`reference/schema/demo-data.md`'s "out of stock" and "exactly one left"
rows are stock levels, and an order-seeder sampling lines at random would
silently drain exactly those states. See that file's own comment for the
full mechanism.

## Local, the full demo

```bash
docker compose exec app php artisan migrate:fresh --seed
docker compose exec app php artisan db:seed --class="Database\Seeders\Demo\DemoSeeder"
docker compose exec app php artisan db:seed --class="Database\Seeders\Demo\DemoCustomerSeeder"
docker compose exec app php artisan db:seed --class="Database\Seeders\Demo\DemoAddressSeeder"
docker compose exec app php artisan db:seed --class="Database\Seeders\Demo\DemoEngagementSeeder"
docker compose exec app php artisan db:seed --class="Database\Seeders\Demo\DemoCartSeeder"
docker compose exec app php artisan db:seed --class="Database\Seeders\Demo\DemoCouponSeeder"
docker compose exec app php artisan db:seed --class="Database\Seeders\Demo\DemoWishlistSeeder"
docker compose exec app php artisan db:seed --class="Database\Seeders\Demo\DemoOrderSeeder"
docker compose exec app php artisan db:seed --class="Database\Seeders\Demo\DemoReviewSeeder"
docker compose exec app php artisan db:seed --class="Database\Seeders\Demo\DemoShowcaseOrderSeeder"
docker compose exec app php artisan db:seed --class="Database\Seeders\Demo\ContentReferenceSeeder"
docker compose exec app php artisan db:seed --class="Database\Seeders\Demo\DemoArticleSeeder"
```

Order matters past `DemoSeeder`, and more of it is load-bearing now than a
simple "after the catalogue":

- `DemoCustomerSeeder` before `DemoAddressSeeder`, `DemoCartSeeder`, and
  `DemoWishlistSeeder` — all three pick from the customers it created.
- `DemoAddressSeeder` before `DemoOrderSeeder` — it passes
  `source_address_id` on orders for customers who have one saved.
- `DemoCouponSeeder` before `DemoOrderSeeder` — it redeems coupons against
  real carts, including closing `ONEUSEONLY`'s one-redemption gap.
- `DemoOrderSeeder` before `DemoReviewSeeder` — reviews require a
  delivered order to exist, enforced by `CreateProductReview` itself, not
  merely by seeding order.
- `DemoEngagementSeeder`, `DemoCartSeeder`, `DemoCouponSeeder`, and
  `DemoWishlistSeeder` have no ordering constraint against each other.
- `DemoReviewSeeder` before `DemoShowcaseOrderSeeder` — case 6 (`ELC-0021`,
  "multiple reviews, mixed ratings") needs real reviews already attached to
  be a genuine case rather than an empty label.

Articles are independent of the transactional pass — they reference
products by slug, not orders — so `ContentReferenceSeeder`/
`DemoArticleSeeder` from "Local with articles too" above can run any time
after `DemoSeeder`.

`StressSeeder` is deliberately last, if run at all — it is opt-in only,
consumes from the same `ProtectedSkus`-filtered pool as `DemoOrderSeeder`,
and its own docblock explains why it is a thin subclass of
`DemoOrderSeeder` rather than a second implementation: a factory-based
fast path would produce orders that reserve no stock, corrupting the
inventory rows the rest of this list depends on being correct.

```bash
docker compose exec app php artisan db:seed --class="Database\Seeders\Stress\StressSeeder"
```

Override the count with `STRESS_SEED_COUNT` (Artisan's `db:seed` does not
forward custom options, so an env var is the practical way to change it):

```bash
docker compose exec -e STRESS_SEED_COUNT=500 app php artisan db:seed --class="Database\Seeders\Stress\StressSeeder"
```

`StressSeeder`'s own docblock names the bottleneck this solves: its order
pool is `ProtectedSkus`-filtered against the ~200-variation demo catalogue,
which runs dry well before 50,000 orders. `CatalogueStressSeeder` is the
companion that removes that ceiling — thousands of additional products
(each with 1 variation, 1 inventory row at `reserved_quantity = 0`, and 1
`product_images` row) for `StressSeeder` to draw from, and for testing
catalogue-scale browsing/pagination/search on its own. The two are
independent; run either without the other.

```bash
docker compose exec app php artisan db:seed --class="Database\Seeders\Stress\CatalogueStressSeeder"
```

Default 5,000 products; override with `CATALOGUE_STRESS_COUNT`:

```bash
docker compose exec -e CATALOGUE_STRESS_COUNT=20000 app php artisan db:seed --class="Database\Seeders\Stress\CatalogueStressSeeder"
```

Inserts directly (`DB::table(...)->insert()`) rather than through
`CreateProduct`/`AddProductVariation` — those Actions are priced for one
call per admin action, not thousands per second. Every generated product
still gets exactly one `is_default` variation and starts with
`reserved_quantity = 0`, so `ReserveStock` has real stock to work with the
same as any other catalogue row; verified by placing a real order against
a stress-generated product through `CreateOrder` directly. Categories and
brands are reused from the existing pool, never created per product — a
naive `ProductFactory::factory()` default would multiply those 1:1 with
products instead.

Every stress product's single image row points at one shared placeholder
file (`storage/app/public/product-images/stress-placeholder.png`, copied
from `public/images/logo.png` the first time this seeder runs) rather than
a real photo — catalogue-scale testing is about row count and query shape,
not visual fidelity, and spending an API call per stress product would
exhaust any free-tier image source in minutes. Stress-generated variations
carry no `attribute_values` — `docs/reference/schema/open-schema-questions.md`
#6 records that as a deferred decision (category/tag-driven attribute
assignment does not exist yet for real catalogue content either), not an
oversight specific to this seeder.

Like `StressSeeder`, this is opt-in only: never wired into `DatabaseSeeder`,
never run in CI, and clearly identifiable afterward — every generated row's
`sku`/`slug` carries a `STRESS-` or `stress-product-` prefix.

## Product images

The demo catalogue's fixtures were authored with placeholder `path` values
(`demo/clm0001-main.jpg`) on the assumption that real files would be added
later — nothing did, so every `product_images` row pointed at a file that
did not exist and every product image was broken in the browser. `demo:fetch-images`
closes that gap by downloading a real photo from Pexels for each row.

```bash
docker compose exec app php artisan demo:fetch-images
```

Requires `PEXELS_API_KEY` in `.env` — a free key from
[pexels.com/api](https://www.pexels.com/api/), no payment info needed.
Pexels' free tier is 25,000 requests/hour, so the whole ~162-product
catalogue finishes in one run — no batching, no rate-limit handling
needed. It is still resumable regardless: every run skips any product
whose expected image files already exist on disk, so re-running after an
interruption costs nothing extra for work already done.

```bash
docker compose exec app php artisan demo:fetch-images --dry-run   # validates without writing
docker compose exec app php artisan demo:fetch-images --limit=20  # process a subset
```

Every downloaded file is re-checked against the exact constants
`ProductImagesRelationManager`'s form enforces
(`ProductImage::MIN_WIDTH_PX`/`MIN_HEIGHT_PX`/`ACCEPTED_MIME_TYPES`/
`MAX_SIZE_KB`) before being accepted — those checks live only in the
Filament form layer, and this command bypasses that form the same way
`FixtureLoader` bypasses it for the rest of the catalogue, so it re-checks
by hand rather than trusting whatever the source returns.

**Why Pexels and not Unsplash or a scraper** — the command's own docblock
carries the full account, worth reading before reaching for a different
source: Unsplash worked correctly but its 50-requests/hour free tier meant
several runs spread across a day; an Apify Google-Images-scraper actor was
tried for speed and instead returned roughly 40% hotlink-protection
placeholder images ("This site does not have permission to serve this
content") indistinguishable from real photos by dimension/MIME checks, and
a colour-variance content detector built to catch them still missed most —
JPEG compression manufactures enough colour variety to defeat that kind of
heuristic. Pexels' own CDN, not arbitrary scraped hosts, is why this one
doesn't have that failure mode. **Do not swap this command back to a
scraper-based source without re-reading that history first.**

This command is **not** part of the seed chain — it is not wired into any
`Demo*` seeder and never should be, since it makes a real network call and
depends on an API key nobody else's environment has. Run it once, by hand,
after the catalogue exists.

## Authoring the demo catalogue

`database/fixtures/SKELETON.md` is the working prompt, including which model
to use, how to batch, and the state-coverage matrix that decides how many
products are out of stock, discounted, or inactive.

The short version:

1. Fill the prompt's `{CATEGORY}`, `{BRAND_SLUGS}`, `{COUNT}`, and
   `{SKU_PREFIX}` placeholders. Brand and category slugs must already exist —
   they come from `CatalogueReferenceSeeder`.
2. Generate 20–25 products per call. 300 in one response does not fit.
3. Save each batch as one file named for its category:
   `database/fixtures/demo/power-tools.json`. A file may hold a single product
   object or an array of them; both load without a splitting step.
4. Validate the whole set before loading any of it.

```bash
docker compose exec app php artisan fixtures:validate
```

**Reference rows must exist before validation means anything.** The validator
resolves every category, brand, and attribute-value slug against real rows,
so running it against a database where `CatalogueReferenceSeeder` has not run
reports every slug in the set as unknown — a wall of failures with one cause.
On a freshly reset database:

```bash
docker compose exec app php artisan migrate:fresh --seed
docker compose exec app php artisan db:seed --class="Database\Seeders\Demo\CatalogueReferenceSeeder"
docker compose exec app php artisan fixtures:validate
docker compose exec app php artisan db:seed --class="Database\Seeders\Demo\DemoSeeder"
```

`DemoSeeder` calls `CatalogueReferenceSeeder` itself, so the third and fourth
commands can be run without the second — but then the validation happens
against the pre-seed state and is worthless. Seed references first.

## Why validate separately

```bash
docker compose exec app php artisan fixtures:validate [path]
```

Defaults to `database/fixtures/demo`. Reports every problem in the set at
once, each with a path — `power-tools.json[11] references unknown attribute
value [colour/teal]` — and exits non-zero.

The database would catch most of these on its own, but one at a time, after
thousands of rows are already written. `DemoSeeder` loads the whole set in a
single transaction for the same reason: a half-loaded catalogue is worse than
none, because the SKUs that did land block the re-run after the fix.

What it checks beyond shape:

- SKU uniqueness across **both** `products` and `product_variations`, and
  across every file in the set, not just within one
- Product slug uniqueness across the set
- Category, brand, and attribute-value slugs resolving against real rows
- `discount_price` strictly below `regular_price`, compared with
  `Money::isLessThan()` rather than `<`, since money is a string
- At least one variation per product, which `CreateProduct` requires anyway
- At most one `is_main` image per document
- Variation gallery keys resolving to an image the same document defines
- No two variations of one product carrying identical attribute values

## Re-running

Every seeder is idempotent on its own key: `updateOrCreate` on `code` for
carriers, on `slug` for categories, brands, and attributes. Re-running
corrects a changed name rather than duplicating a row.

`DemoSeeder` and `DemoArticleSeeder` are **not** idempotent — products and
articles are created, not upserted, so a second run fails on the first
duplicate SKU or article slug. Reload with `migrate:fresh` rather than by
running either twice.

## Troubleshooting

**`No .json documents in ...`** — the fixture directory is empty. See
`database/fixtures/SKELETON.md`.

**`Fixture references unknown category slug [x]`** from the loader rather
than the validator — `CatalogueReferenceSeeder` has not run, or the slug is
genuinely absent. Run `fixtures:validate` first; it reports all of them at
once instead of failing on the first.

**A `migrate:fresh --seed` that hangs or fails oddly** — check
`how-to/troubleshooting.md` before assuming the seeder is at fault. Several
of this project's environment errors present as seeder failures.
