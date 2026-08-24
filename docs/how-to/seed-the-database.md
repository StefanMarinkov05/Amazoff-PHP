# How to seed the database

Which seeder to run for which situation, and how to author the demo
content. ADR-0003 is why there are several rather than one;
`reference/schema/fixture-format.md` and `reference/schema/article-fixture-format.md` are
the two JSON shapes.

## The seeders

| Seeder | Runs where | Contents | Command |
|---|---|---|---|
| `DatabaseSeeder` | CI, local, production | Permissions, roles, carriers, staff accounts | `php artisan db:seed` |
| `DemoSeeder` | Local, deployed demo | Committed catalogue fixtures | `php artisan db:seed --class=DemoSeeder` |
| `DemoArticleSeeder` | Local, deployed demo | Committed article fixtures | `php artisan db:seed --class=DemoArticleSeeder` |
| `DemoCustomerSeeder` | Local, deployed demo | 100 factory customers, no role | `php artisan db:seed --class=DemoCustomerSeeder` |
| `DemoCartSeeder` | Local, deployed demo | Carts for ~35% of customers plus a few guest carts | `php artisan db:seed --class=DemoCartSeeder` |
| `DemoCouponSeeder` | Local, deployed demo | 6 coupons, one per required state | `php artisan db:seed --class=DemoCouponSeeder` |
| `DemoWishlistSeeder` | Local, deployed demo | Wishlists for ~20% of customers | `php artisan db:seed --class=DemoWishlistSeeder` |
| `StressSeeder` | Local only | Programmatic volume — **not built yet** | — |

The four `Demo*` seeders below `DemoArticleSeeder` are **not fixtures** —
no JSON shape, nothing for an LLM to author. `reference/schema/fixture-format.md`'s
"What a fixture file actually contains" section is why: customers, carts,
coupons, and wishlists are pure state, which a seeder's own distribution
states more reliably than a hand-written document restates it. They read the
catalogue and customers already loaded, so they run after both — see below.

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
docker compose exec app php artisan db:seed --class=DemoSeeder
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
docker compose exec app php artisan db:seed --class=CatalogueReferenceSeeder
docker compose exec app php artisan db:seed --class=ContentReferenceSeeder
docker compose exec app php artisan db:seed --class=DemoSeeder
docker compose exec app php artisan db:seed --class=DemoArticleSeeder
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

`DemoCartSeeder`, `DemoCouponSeeder`, and `DemoWishlistSeeder` write carts,
coupons, and wishlists directly — none of those three carries an invariant a
seeder writing plain Eloquent rows can violate. Nothing seeds orders,
payments, shipments, reservations, coupon redemptions, or status history.
ADR-0003 is explicit that transactional data is produced by running the
Actions that produce it in real use — so a seeded order is reachable, and
explicable, the same way a real one is. There is no JSON shape for an order
on purpose.

## Local, the full demo

```bash
docker compose exec app php artisan migrate:fresh --seed
docker compose exec app php artisan db:seed --class=DemoSeeder
docker compose exec app php artisan db:seed --class=DemoCustomerSeeder
docker compose exec app php artisan db:seed --class=DemoCartSeeder
docker compose exec app php artisan db:seed --class=DemoCouponSeeder
docker compose exec app php artisan db:seed --class=DemoWishlistSeeder
```

Order matters past `DemoSeeder`: `DemoCustomerSeeder` has to run before
`DemoCartSeeder` and `DemoWishlistSeeder`, both of which pick from the
customers it created. `DemoCartSeeder`, `DemoCouponSeeder`, and
`DemoWishlistSeeder` have no ordering constraint against each other. Add
`ContentReferenceSeeder`/`DemoArticleSeeder` from "Local with articles too"
above if articles are wanted too.

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
docker compose exec app php artisan db:seed --class=CatalogueReferenceSeeder
docker compose exec app php artisan fixtures:validate
docker compose exec app php artisan db:seed --class=DemoSeeder
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
