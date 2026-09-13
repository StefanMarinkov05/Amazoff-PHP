# Performance testing — findings at catalogue scale

Part of the [testing reference](README.md). What a load test against a
stressed-scale catalogue actually found, with the query log and `EXPLAIN`
output behind each claim — not a coverage percentage, a dated record of
what was measured and what it showed.
[`how-to/measure-performance-under-load.md`](../../how-to/measure-performance-under-load.md)
has the reproducible procedure; this page is the results.

## 2026-09-14 — `CatalogueStressSeeder` at 100,000 products

Local dev database, seeded catalogue (169 products, 173 categories, 34
brands) plus 100,000 stress-generated products
(`CATALOGUE_STRESS_COUNT=100000`), 100,169 total. Measured in-process
(`app()->handle()` against a real `Illuminate\Http\Request`, `DB::enableQueryLog()`
around it) rather than over real HTTP, so the numbers exclude network and
browser render time — they are the server-side floor, not the number a
visitor's browser would see.

### Finding 1 — `ResolveCategoryFamily::selfAndDescendantIds()` re-queries the category table once per category

**What was measured.** Loading `/catalogue` with no filter issued 201
queries in ~1950ms server time. 173 of them were the exact same query,
byte for byte:

```sql
select `id`, `parent_id` from `product_categories` where `parent_id` is not null
```

173 is not a coincidence — it is the exact category count in this
database. `ProductList::categories()` calls
`ResolveCategoryFamily::selfAndDescendantIds($category)` once per category
while building the sidebar's per-category product counts
(`app/Livewire/Catalogue/ProductList.php`, `categories()`), and
`selfAndDescendantIds()` calls the private `childrenByParentId()` fresh on
every invocation — one query, correctly, per call, but with no caching
across calls in the same request. 173 categories → 173 identical queries.

**The docblock is wrong about this.** `categories()`'s own comment claims
"one query for the category list... `ResolveCategoryFamily` never
re-queries per row" — true for the `ProductCategory::query()->get()` call
immediately above it, false for the `selfAndDescendantIds()` call inside
the `->map()` right after. The comment describes the *intended* shape, not
the code as it stands.

**Cost at this scale.** Individually cheap — 173 queries at ~1.3ms each is
roughly 225ms of the ~1950ms total, not the dominant cost today. Growing
linearly with category count, not product count, so it does not get worse
as the stress seeder's product count grows, but it does get worse if the
category tree itself grows, and 173 round-trips for data that fits in one
query is wrong regardless of the current cost.

**Not yet fixed.** A `static` in-memory cache on `childrenByParentId()`
scoped to the request (or hoisting one `childrenByParentId()` call out of
`categories()`'s loop and threading it through) would collapse this to the
1 query the docblock already claims happens.

### Finding 2 — `products.is_available` and `products.deleted_at` carry no index

**What was measured.** `EXPLAIN` on the per-category product-count query
`/catalogue` runs on every load:

```sql
EXPLAIN SELECT product_category_id, count(*) as total FROM products
WHERE is_available = 1 AND products.deleted_at IS NULL
GROUP BY product_category_id;
```

```
type: index · key: products_product_category_id_foreign
rows: 95129 · filtered: 1.00%
```

`rows: 95129` of 100,169 — MySQL walks nearly the entire
`product_category_id` index and discards 99% of what it reads, because
`is_available`/`deleted_at` (the two columns actually narrowing the result)
have no index of their own to filter by first. `SHOW INDEX FROM products`
confirms: only `product_category_id` and `brand_id` carry an index;
`is_available` and `deleted_at` do not.

The same shape appears in the per-brand product-count subquery
(`app/Livewire/Catalogue/ProductList.php`'s brand-facet query) — indexed on
`brand_id` (`type: ref`, `rows: 5` per brand, correctly narrow), but the
`is_available`/`deleted_at` filter *inside* each per-brand lookup still
costs a row-by-row check.

**Cost at this scale.** 136ms for the category-count query, 306ms for the
brand-count query (34 brands × a per-brand correlated subquery) — the two
largest single costs on a plain, unfiltered `/catalogue` load. Scales
linearly with total product count regardless of how selective the actual
filter is, because the index MySQL does have cannot narrow on the columns
that matter here.

**Not yet fixed.** A composite index — `(is_available, deleted_at,
product_category_id)` covers the category-count query outright; whether
`brand_id` needs its own composite depends on measuring after the first
change, not before. Migrations are append-only after the schema freeze
(`CLAUDE.md`), so this is a new migration, not an edit to an existing one.

### Finding 3 — catalogue search's `LIKE '%term%'` is a full table scan

**The dominant cost.** `/catalogue?search=variation` took ~3861ms server
time — roughly double the unfiltered page — driven almost entirely by
three queries, each well over half a second:

```sql
EXPLAIN SELECT count(*) as aggregate FROM products
WHERE is_available = 1
  AND (name LIKE '%variation%' OR short_description LIKE '%variation%')
  AND products.deleted_at IS NULL;
```

```
type: ALL · key: NULL · rows: 95129 · filtered: 0.21%
```

`type: ALL`, no key at all. A leading-wildcard `LIKE` can never use a
B-tree index by construction — MySQL cannot know where in `name` the term
might start, so it reads every row. This is the same trade
`write-rules/catalogue.md`/ADR-0014 already names for the search feature
(a B-tree index cannot serve `LIKE '%term%'`); what this load test adds is
the *measured* cost of that known trade at a size the 169-product demo
catalogue never reached: a near-full scan of the whole table, three times
in one page load (brand facet, category facet, total count), each roughly
as expensive as the unfiltered page's *entire* query set combined.

**Cost at this scale.** ~937ms (brand facet) + ~891ms (category facet) +
~761ms (total count) ≈ 2.6s of the page's 3.9s. This is the one finding
here that is a real, presentation-relevant risk if the catalogue is ever
seeded at a size closer to 100k than 169 — search would be the first thing
a visitor notices being slow, not an edge case.

**Not yet fixed, and not a one-line fix.** A B-tree index cannot serve this
query shape at all; the real fix is a full-text index
(`ALTER TABLE ... ADD FULLTEXT`) or an external search service, either of
which changes the query itself, not just adds an index to the existing
one — out of scope for this load-test pass. Recorded here as a measured,
scale-dependent cost rather than left as an assumption.

### Finding 4 — the product detail page holds up at 500 variations and 200 images (a negative result, recorded on purpose)

**What was measured.** No stress seeder exists for variation/image depth on
a single product — `CatalogueStressSeeder` gives every generated product
exactly 1 variation and 1 image, so catalogue *breadth* was covered above
but product *depth* was not. Built one product with 100 variations/50
images through `ProductVariation::factory()`/`ProductImage::factory()`
(not raw inserts, so every model event and default still ran), then a
second at 500 variations/200 images, and measured `/products/{slug}` the
same way as the catalogue above.

| | Baseline (5 var / 2 img, seeded demo max) | 100 var / 50 img | 500 var / 200 img |
|---|---|---|---|
| Elapsed | 101ms | 89ms | 115ms |
| Query count | 25 | 19 | 19 |

**Query count does not grow with variation or image count at all** — 19
queries flat across a 5× increase in both. `ProductDetails`'
`with(['attributeValues.attribute', 'inventory', 'images'])` and
`with(['brand', 'productCategory.parent', 'productImages',
'productSpecifications', 'descriptiveAttributeValues.attribute'])`
(`app/Livewire/Catalogue/ProductDetails.php`) are doing exactly what they
claim: one query per relation, not one per row. The ~26ms growth from 89ms
to 115ms is real row-processing cost (PHP iterating more models), not a
query-count problem.

**Recorded as a negative result on purpose.** Not every load-test pass
finds a bug, and a page checked and found sound is worth writing down —
the alternative is re-suspecting this page every time someone changes
`ProductDetails` without a note saying it was already measured clean.
Stress products removed after measuring
(`Product::whereIn('id', [...])->each(fn ($p) => ...->forceDelete())`) —
this one was not built through the demo/stress seeders, so cleanup was by
hand rather than `migrate:fresh --seed`.

## 2026-09-14 — `DeepCatalogueStressSeeder`: 100,000 products, 2.5M variations, 5M images, real scale

Finding 4 above isolated variation/image *depth* on one hand-built product
against an otherwise-normal database. This run corroborates it at real,
combined scale: both catalogue *breadth* (100,000 products) and per-product
*depth* (20–30 variations, up to 50 images each) at once, seeded through
`Database\Seeders\Stress\DeepCatalogueStressSeeder` — a genuine INSERT
path exercising the same foreign keys, the same gallery pivot, the same
`is_default`/`is_main` invariants an admin-authored product would, not a
factory shortcut.

**Seed cost, stated with no ambiguity:**

```
DEEP_STRESS_COUNT=100000 php artisan db:seed --class="Database\Seeders\Stress\DeepCatalogueStressSeeder"

created 100,000 products, 2,500,265 variations, 5,000,000 images,
13,753,252 gallery-pivot rows, in 723.3 seconds (12 minutes 3 seconds).
```

That is 138.3 products/second sustained, and the per-2,000-product
checkpoint log shows **linear scaling start to finish** — the last 2,000
products (98,000→100,000) took 14.1s, the first logged batch (0→2,000) a
comparable 14.0s. No slowdown as the tables grew from empty to millions of
rows. Confirmed row counts after completion, queried directly, not taken
from the seeder's own running total:
`Product::count()` → 100,169 · `ProductVariation::count()` → 2,500,484 ·
`ProductImage::count()` → 5,000,181 (the extra 169/484/181 over the
round seed numbers are the pre-existing demo catalogue, left in place).

**Page-load cost against the full 5-million-image, 2.5-million-variation
database, stated with no ambiguity:**

| Page | Elapsed | Query count | Note |
|---|---|---|---|
| `/catalogue` (unfiltered) | **2,267ms** | 203 | Matches Finding 1/2's shape exactly — same 173-query category N+1, same unindexed-column table scan, now against a table 2× the earlier run's size |
| `/catalogue?search=product` | **2,177ms** | — | A broadly-matching term; confirms Finding 3's full-table-scan still holds (`EXPLAIN`: `type: ALL`, `rows: 99218`, `0.21%` filtered) at 2× scale |
| `/products/deep-stress-product-170` (27 real variations, 50-image gallery) | **100–126ms** (4 runs: 126.4, 106, 100, 101.2) | **22** | The number this section exists to state plainly: a single product page, with a real variation switcher and a real image gallery, loads in **about a tenth of a second** inside a database holding 5,000,000 image rows and 2,500,000 variation rows. No query above 2ms; the ~100ms is PHP hydrating 27 models and their galleries, not the database. |

**The comparison worth making plain:** the catalogue *list* page (2,267ms)
is over 18× slower than a single *product* page (100–126ms) in the same
database — confirming Findings 1–3 are catalogue-page-specific (the N+1,
the missing index, the `LIKE` scan) and not a property of scale in
general. A visitor loading one product loads it fast, at a scale two full
orders of magnitude past anything this catalogue will hold in practice; a
visitor loading the catalogue index pays for three unrelated,
already-identified, not-yet-fixed problems every single time.

**Cleanup.** `migrate:fresh --seed` after measuring — the stress rows do
not belong in the seeded demo state the rest of the testing docs and any
presentation assume is present (`schema/demo-data.md`).

## What this page is not

Not a fix — none of the three findings above have a shipped change yet;
each says so explicitly rather than implying otherwise. Not a full
performance audit — this pass drove `/catalogue` and `/catalogue?search=`
only, the two pages `preventLazyLoading()` being off (ADR-0012) makes
riskiest, not every route. Not a repeatable automated test — the numbers
here are a manual, dated measurement; see
[`how-to/measure-performance-under-load.md`](../../how-to/measure-performance-under-load.md)
before trusting them as still current, and re-measure rather than assuming
they still hold after a schema or query change in this area.

**Not a concurrent-load/throughput test, deliberately.** Everything above
measures one request at a time against a database seeded to a large *size*
— it answers "does a query slow down as the table grows," not "does the
app hold up when many users hit it *simultaneously*." That second
question is real and has a real answer today, just not from this page:

- **Race correctness** — does concurrent access corrupt state (a checkout
  race creating negative stock, a coupon redeemed past its cap) — is
  already proven, thoroughly, by `tests/Concurrency/`'s 20 files, each
  running two real processes against the same row and verified by
  deletion (remove the lock, watch the test fail). This is the
  concurrency failure mode that actually matters for a shop handling real
  money, and it is covered.
- **Raw throughput under concurrent HTTP load** — hundreds of simulated
  users hitting checkout/contact-form/payment at once, measured with
  something like k6 or `wrk` — is not covered, and was deliberately not
  built during this pass. Two reasons: it is not something §37 asks for,
  and a number produced by this local Docker Compose stack (PHP-FPM's
  worker count, MySQL's tuning, both defaulted for solo dev use) would not
  predict Railway's actual production capacity — the ceiling it found
  would be a property of this laptop, not of the application. Worth doing
  if a real deploy's capacity is ever actually in question; not worth the
  time against a grading deadline for a number that would not transfer.
