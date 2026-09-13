# How to measure performance under load

The procedure behind
[`reference/testing/performance-testing.md`](reference/testing/performance-testing.md)'s
findings — seed the catalogue at scale, then measure real query counts and
timing against it, rather than guessing from reading the query builder
code. `preventLazyLoading()` is deliberately off (ADR-0012), so an N+1 here
is silent until someone actually counts queries.

## 1. Seed at scale

`database/seeders/Stress/CatalogueStressSeeder` — see
[`seed-the-database.md`](seed-the-database.md), "Local with a stress
catalogue" for what it writes and why it inserts directly rather than
through `CreateProduct`. Default 5,000 products; override with
`CATALOGUE_STRESS_COUNT`:

```bash
docker compose exec -e CATALOGUE_STRESS_COUNT=100000 app php artisan db:seed --class="Database\Seeders\Stress\CatalogueStressSeeder"
```

Takes roughly 25s for 100,000 rows locally. Additive — it does not touch
the existing demo catalogue, so `App\Models\Product::count()` afterward is
the stress count plus whatever was already there.

**This mutates the local dev database.** Reset with
`docker compose exec app php artisan migrate:fresh --seed` (or `demo:seed
--fresh` for the full demo set) once done measuring — don't leave the
stress catalogue in place for a browser click-through or a presentation,
where the seeded catalogue's specific known-state products
(`schema/demo-data.md`) are what the rest of the testing docs assume is
there.

## 2. Measure a real request in-process

Real HTTP through the browser adds network and render time on top of what
the server actually costs, and a browser MCP tool (Playwright/Chrome
DevTools) gives a network waterfall, not a query count. For the number
that actually says whether a page is slow because of the database,
run the request through Laravel directly, inside the container, with the
query log on:

```bash
docker compose exec -T app php artisan tinker --execute='
use Illuminate\Support\Facades\DB;
DB::enableQueryLog();
$start = microtime(true);
$request = \Illuminate\Http\Request::create("/catalogue", "GET");
$response = app()->handle($request);
$elapsed = microtime(true) - $start;
echo "Status: " . $response->getStatusCode() . "\n";
echo "Elapsed: " . round($elapsed * 1000, 1) . "ms\n";
echo "Query count: " . count(DB::getQueryLog()) . "\n";
'
```

Append a query string (`/catalogue?search=term`, `/catalogue?category=slug`)
to measure a filtered request the same way — the search and filter paths
cost differently from a plain load, and both are worth checking separately
rather than assuming one number represents the page.

## 3. Find what is actually slow

Query count alone does not say which query costs the most. Sort the log by
time and print the slowest few:

```bash
docker compose exec -T app php artisan tinker --execute='
use Illuminate\Support\Facades\DB;
DB::enableQueryLog();
$request = \Illuminate\Http\Request::create("/catalogue", "GET");
app()->handle($request);
$log = DB::getQueryLog();
usort($log, fn($a, $b) => $b["time"] <=> $a["time"]);
foreach (array_slice($log, 0, 8) as $q) {
    echo "{$q["time"]}ms: " . substr($q["query"], 0, 140) . "\n";
}
'
```

To find an N+1 specifically — many queries, not necessarily slow ones,
sharing the same shape — group by the query text with the bind values
stripped rather than by time:

```bash
docker compose exec -T app php artisan tinker --execute='
use Illuminate\Support\Facades\DB;
DB::enableQueryLog();
$request = \Illuminate\Http\Request::create("/catalogue", "GET");
app()->handle($request);
$grouped = [];
foreach (DB::getQueryLog() as $q) {
    $normalized = preg_replace("/\d+/", "N", $q["query"]);
    $grouped[$normalized] = ($grouped[$normalized] ?? 0) + 1;
}
arsort($grouped);
foreach (array_filter($grouped, fn($c) => $c > 1) as $sql => $count) {
    echo "x{$count}: " . substr($sql, 0, 140) . "\n";
}
'
```

A count near a known table size (the category count, the brand count) is
the tell — it means something is looping over that table's rows and
issuing one query per row instead of one query total.

## 4. `EXPLAIN` the slow ones

`mcp__laravel-boost__database-query` runs a read-only query directly
(`SELECT`, `SHOW`, `EXPLAIN`, `DESCRIBE` only) without a tinker round-trip —
the faster way to run `EXPLAIN` once a candidate query is known from step 3.
Read from the query log's own SQL (bind values show as `?`; substitute a
representative real value) rather than reconstructing the query by hand,
since the query builder's actual output can differ from what the
Eloquent call looks like it would produce.

What to read from the result:

- **`type: ALL`** — a full table scan. No index is helping at all; the
  common cause here is a leading-wildcard `LIKE '%term%'`, which cannot use
  a B-tree index regardless of what is indexed (a full-text index or an
  external search service is the real fix, not a new plain index).
- **`rows` close to the table's total row count, with a low `filtered`
  percentage** — an index is narrowing on the wrong column. The index
  exists and is being used (`type` will say `ref` or `index`, not `ALL`),
  but the column actually doing the filtering in the `WHERE` clause has no
  index of its own, so MySQL walks a large slice of what the existing
  index covers and discards most of it afterward.
- **`type: ref` with a small `rows` per row of the driving table** — the
  healthy shape for a correlated subquery (a per-brand or per-category
  count): the index is doing real work, and per-row cost is small even if
  the total across many rows adds up.

`SHOW INDEX FROM <table>` lists what is actually indexed today, faster than
reading migrations to reconstruct it.

## 5. Record it, don't just fix it silently

A number measured once and not written down cannot be compared against
next time. `reference/testing/performance-testing.md` is where a finding
from this procedure goes — the query, the `EXPLAIN` output, and the
timing, dated, in the same style as `stripe-testing.md`'s and
`security-testing.md`'s own honest-findings pages. If a finding gets
fixed, that page's "not yet fixed" note is the thing to update, alongside
the usual `CHANGELOG.md` entry for the fix itself.

## Watch the container while measuring

A heavier-than-usual seed or query load competes with anything else the
`app` container is doing. `docker compose ps` before trusting a surprising
number — `troubleshooting/concurrency-and-testing-races.md`'s "`pest
--testsuite=Feature,Unit,Concurrency` fails a handful of unrelated
`Feature` tests" entry has a worked example of a background container
issue producing numbers that looked like a code regression and were not.
