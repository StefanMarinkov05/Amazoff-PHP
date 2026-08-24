# Demo seeding — how it fits together

What the demo dataset is, why it is built the way it is, and how the pieces
in `database/seeders/Demo/` and `Stress/` relate to each other. The
individual seeders' own docblocks carry the specifics this page does not
repeat; `how-to/seed-the-database.md` is the run-order recipe.

## Three seeder groups, one reason for the split

`database/seeders/` is organized into `Demo/`, `System/`, and `Stress/` by
purpose, not alphabetically. `System/` is what every environment needs
regardless of demo content — permissions, roles, carriers, staff accounts —
and runs everywhere, including production, through the root
`DatabaseSeeder`. `Demo/` produces presenter-facing content: the catalogue,
customers, addresses, carts, coupons, orders, reviews, articles. `Stress/`
produces volume for query-plan and pagination testing and is never
presented — its two seeders are opt-in, never wired into `DatabaseSeeder`,
never run in CI.

## Why transactional data is built by running Actions, not fabricated

`DemoOrderSeeder` builds each of its 140 orders by creating a real cart,
adding real line items, and checking it out through `CreateOrder`, then
walking the resulting order through `TransitionOrderStatus`,
`RecordPayment`, `TransitionPaymentStatus`, `CreateShipment`, and
`TransitionShipmentStatus` — the same Actions a real checkout and a real
staff member would call. It never uses `Order::factory()`.

Two reasons, and the second is the one that actually bites if ignored.
First, ADR-0003 requires seeded transactional history to be reachable the
same way real history is — a seeded order should hold up to the same
scrutiny as one a real customer placed. Second, `CreateOrder` composes
`ReserveStock` itself as part of checkout. A factory-built order reserves
nothing, so a script that fabricates `Order`/`OrderItem` rows directly and
then tries to walk the status forward dies the moment it reaches `Shipped`,
because `TransitionOrderStatus` composes `CompleteSale`, which throws
`InvalidArgumentException` — `Cannot complete a sale of N for variation
...: only 0 reserved.` Building through a real cart checkout makes that
trap structurally impossible rather than something to remember.

The status, payment-method, guest/registered, and coupon distribution
across the 140 orders is assigned by rule, not sampled — every
`OrderStatus`, `PaymentStatus`, and `ShipmentStatus` case is guaranteed to
appear at least once, including the two least likely to occur by chance:
`Failed` (with 2 of 4 recovering to `Paid`, a legal edge easy to assume
impossible) and `PartiallyRefunded` (including 2 orders taking two
successive partial refunds that together still fit — the only accumulating
transition in the enum). `DemoOrderSeeder`'s own constants
(`STATUS_DISTRIBUTION`, `COUPON_ASSIGNMENT`) are the authoritative numbers;
`reference/schema/demo-data.md` is where they are verified against the
live database after a run, not merely asserted.

Cash on delivery orders follow a distinct path through the same walk: they
skip `AwaitingPayment`/`Paid` entirely (`New => Confirmed` directly, the
edge `OrderStatus` carries specifically for COD) and open their payment
only after `Delivered`, marked paid on remittance — never before, since
nothing was actually collected until the courier did. A `Refunded`
order-status target that never reached `Delivered` would have no payment
to refund at all for a COD order, which is why every `Refunded`-target walk
routes through `Delivered` regardless of payment method, rather than the
more direct `Shipped => Returned` path that is legal for the order status
alone but produces a state with nothing to refund.

`DemoReviewSeeder` runs after `DemoOrderSeeder` for the same reason
ADR-0003 gives above: `CreateProductReview` enforces §24's
verified-purchase rule itself — a delivered order, same user, same
product — so there is nothing to review before a delivered order exists.
No Action takes a date, so every order's and review's timestamps are
back-dated afterward with direct Eloquent writes, spread across the last 9
months, weighted toward the recent end. That is the one place in this pass
where writing rows directly instead of through an Action is correct: the
thing being changed is a clock, not domain state.

## What `ProtectedSkus` protects, and why it had to exist

`reference/schema/demo-data.md`'s coverage table names specific SKUs as
"out of stock" (`current_quantity = 0`) or "exactly one left" — states the
demo catalogue was deliberately authored to hold, so a presenter can pull
up the add-to-cart refusal path or the quantity-validation boundary on
demand. Those states are stock levels, and `TransitionOrderStatus` on
`=> Shipped` composes `CompleteSale`, which moves stock from reserved to
sold and permanently drops `current_quantity`. An order seeder sampling
lines at random would silently drain exactly those SKUs the first time it
happened to pick one, and the failure would be invisible — the seeder
succeeds, the orders look correct, and the documented state is simply gone
until someone opens the presenter's page mid-demo and finds it wrong.

`App\Support\ProtectedSkus` reads
`database/fixtures/reference/protected-skus.json` — the exclusion list,
with its reasoning inline in the file itself — and both `DemoOrderSeeder`
and the two `Stress/` seeders consult it before adding any line to a cart
or generated order. `assertSelectable()` throws rather than returning
false, deliberately: a seeder that silently skipped a protected line would
write a set quietly smaller than the distribution it claims to satisfy.
Verified against real rows after every catalogue re-seed, not assumed
stable: all 19 excluded SKUs resolve to real products or variations, and
the guard has been proven to hold under load significantly past the
140-order demo scale it was designed for — a 2,000-order `StressSeeder`
run produced zero protected-SKU violations and zero inventory overdraw.

## Two stress seeders, two different bottlenecks

`StressSeeder` produces order volume against the *existing* catalogue —
more orders, more payments, more shipments, same ~169 products. It hits a
ceiling that has nothing to do with the Actions themselves: its selectable
pool is the demo catalogue's variations minus whatever `ProtectedSkus`
excludes, which is roughly 200 rows. A 2,000-order run against that pool
already produced failures once it ran dry — not a bug, but a real, observed
scale limit `StressSeeder`'s own docblock records.

`CatalogueStressSeeder` removes that ceiling by growing the pool itself:
thousands of additional products, each with one variation and one
inventory row starting at `reserved_quantity = 0`, bulk-inserted directly
rather than through `CreateProduct`/`AddProductVariation` — those Actions
are priced for one call per admin action, not thousands per second.
Every generated product's single image row points at one shared
placeholder file rather than a real photo, copied from `public/images/logo.png`
the first time the seeder runs: catalogue-scale testing needs row count
and query shape, not visual fidelity, and spending an image-API call per
generated product would exhaust any free-tier source in minutes for a
result nobody will ever look at. Categories and brands are drawn from the
existing pool rather than created per product, since `ProductFactory`'s
own default would otherwise multiply those 1:1 with products and turn a
catalogue-scale test into a category-scale one. The two stress seeders are
independent — either runs without the other — but `StressSeeder` at high
volume needs `CatalogueStressSeeder`'s larger pool to avoid the same
exhaustion its own docblock already documents once.

Generated stress products carry no `attribute_values` on their variations.
`open-schema-questions.md` #6 records why this is a deferred decision
rather than an oversight: nothing in the system today derives a default
attribute set from a product's category, so attribute assignment is either
manual per fixture (the demo catalogue's actual path) or absent (this
seeder's path) — there is no third option to reach for yet.

## Why product images went through three sources

The demo catalogue's fixture documents were authored with placeholder
`product_images.path` values — `demo/clm0001-main.jpg` and similar — on the
expectation that real files would be added later. Nothing did, so every
product image in the running application was broken until `demo:fetch-images`
was built to close the gap. Getting there took three attempts, and the
reasoning for abandoning the first two is worth keeping somewhere more
durable than a git log, since the failure modes were not obvious in
advance.

**Unsplash** worked correctly — 0 contaminated files across the 25 it
produced before the pass moved on — but its free tier caps at 50
requests/hour, and the command needs roughly one search per product. A
~162-product catalogue meant several runs spread across a day rather than
one sitting.

**Apify's `hooli/google-images-scraper` actor** was tried next specifically
to remove that hourly wait — it has no comparable cap. A real run against
it showed the actual cost: roughly 40% of "downloaded images" were not
photos at all but a hotlink-protection placeholder graphic ("This site does
not have permission to serve this content") that certain scraped CDN hosts
return instead of the real image when fetched without a browser session. A
placeholder like that is a syntactically valid, correctly-sized image, so
dimension and MIME checks pass it cleanly. A GD colour-variance detector
was built on the theory that a mostly-blank image with sparse text has far
fewer distinct colours than a real photograph — true in principle, but a
second full run showed it still missed most of the contamination, because
JPEG compression and antialiased, rotated placeholder text manufacture
enough incidental colour variety to defeat that heuristic. This was
confirmed by duplicate-file hashing on real output, not assumed from
theory, both before and after the detector existed.

**Pexels** is where the command landed: same reliability shape as
Unsplash — a licensed photo API returning its own CDN URLs, not arbitrary
third-party hosts — but a 25,000-requests/hour free tier, so the entire
catalogue finishes in a single run with no batching or resumability
handling needed. `demo:fetch-images`'s own docblock keeps this same account
so the reasoning travels with the code, not only with this page.

The 182 resulting files are committed to the repository —
`storage/app/public/.gitignore`'s default exclusion of uploaded content is
overridden specifically for the `demo/` subdirectory — so a fresh clone
plus `migrate:fresh --seed` produces a catalogue with working images and no
API key required. Re-running `demo:fetch-images` is only necessary if the
fixture set grows past what is already committed; the command is
resumable and skips any row whose file already exists on disk regardless.
