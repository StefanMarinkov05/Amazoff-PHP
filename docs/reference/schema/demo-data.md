# Demo data — what exists and where to find it

A lookup table for a live demo or a presentation: every notable state in the
seeded catalogue, with the exact SKU to pull up. Everything below was
verified against a real seeded database — not the JSON fixture shape, the
actual rows — on 2026-08-24 for the catalogue, and re-verified on 2026-09-05
for the transactional pass (orders, payments, shipments, reviews) after the
showcase-account and adaptive-review changes. Re-seeding on the current fixture set should reproduce
all of it; if a number here stops matching, the fixture set moved and this
page is what's stale.

## Load it

Run order matters past `DemoSeeder` — `DemoOrderSeeder` redeems coupons
(needs `DemoCouponSeeder` first) and reads saved addresses (needs
`DemoAddressSeeder` first); `DemoReviewSeeder` needs delivered orders
(needs `DemoOrderSeeder` first); articles need the product catalogue
(`DemoSeeder`) but nothing else on this list.

```bash
docker compose exec app php artisan demo:seed --fresh
```

`Demo\DemoDatabaseSeeder` is what that calls, and it is where the run-order
constraints live. The explicit thirteen-command form is in
`docs/how-to/seed-the-database.md` if a single step needs running alone.

Every `Demo*` and reference seeder now lives under
`database/seeders/Demo/`, `System/` (permissions, roles, carriers, staff
accounts), or `Stress/` — `db:seed --class=` needs the fully-qualified
class name once a seeder is namespaced under a subfolder; a bare basename
no longer resolves. `docs/how-to/seed-the-database.md` is the full
reference for these commands and their run-order constraints.

**`StressSeeder` is not part of this list.** It exists for query-plan and
pagination testing against a large table, produces no narrative demo
states, is never wired into `DatabaseSeeder`, and is never run in CI. Run
it, if at all, after everything above:

```bash
docker compose exec app php artisan db:seed --class="Database\Seeders\Stress\StressSeeder"
```

Default 2000 orders; override with `STRESS_SEED_COUNT`
(`docker compose exec -e STRESS_SEED_COUNT=500 app php artisan db:seed
--class="Database\Seeders\Stress\StressSeeder"`).

## Scale, at a glance

| Table | Count |
|---|---|
| `products` | 169 |
| `product_variations` | 219 |
| `product_images` | 182 (159 distinct files, 23 legitimate category re-uses — all real, verified) |
| `product_categories` | 173 (122 hold at least one product) |
| `brands` | 34 (33 used) |
| `attributes` / `attribute_values` | 9 / 55 |
| customers (`DemoCustomerSeeder`) | 100 |
| `addresses` (`DemoAddressSeeder`) | 78, across 60 of 100 customers |
| `newsletter_subscribers` | 80 (66 subscribed, 14 unsubscribed) |
| `contact_messages` | 32 (20 handled, 12 open), drawn from a 40-entry pool in `fixtures/reference/contact-messages.json` |
| `carts` | 44 pre-checkout demo carts (8 guest) + 158 checked-out by `DemoOrderSeeder` |
| `cart_items` | 116 on the pre-checkout carts |
| `wishlist_items` | 62 |
| `coupons` | 6 |
| `orders` | 158, distribution below — 140 distributed + 18 pinned to the two named accounts |
| `order_items` | 405 |
| `order_addresses` | 316 |
| `order_status_histories` | 674 |
| `payments` | 118 |
| `shipments` | 89 |
| `shipment_tracking_events` | 218 |
| `coupon_redemptions` | 23 |
| `product_reviews` | ~126 (88 approved, 38 pending) — adaptive, see below |
| `articles` | 24 (16 published, 4 draft, 3 scheduled, 1 archived) |

## Order and payment states

The 158 orders `DemoOrderSeeder` produces, by status — verified against the
live database by the seeder's own reporting pass after it runs. The 140
distributed orders are exact; the 18 pinned to the named accounts sit on top,
so the totals below are the sum:

| `OrderStatus` | Count |
|---|---:|
| `Delivered` | 66 |
| `Shipped` | 15 |
| `Cancelled` | 14 |
| `Confirmed` | 12 |
| `AwaitingPayment` | 10 |
| `Preparing` | 9 |
| `New` | 8 |
| `ReadyForShipment` | 7 |
| `Paid` | 7 |
| `Returned` | 6 |
| `Refunded` | 4 |

### One account holds every state

**`customer@example.com` (password `password`) owns all eleven
`OrderStatus` cases by itself** — the point being that a demo can walk the
entire order lifecycle on one login instead of hunting for an account that
happens to own the state it wants to show. Its 14 pinned orders land on
consecutive serials at the end of the range, so they are easy to find:

| Serial | Status | Method | Notes |
|---|---|---|---|
| `ORD-000141` | `New` | Stripe | no payment row yet |
| `ORD-000142` | `AwaitingPayment` | Stripe | payment `pending` — the intent-open state |
| `ORD-000143` | `Paid` | Stripe | carries `WELCOME10` |
| `ORD-000144` | `Confirmed` | COD | COD skips `AwaitingPayment`/`Paid` entirely |
| `ORD-000145` | `Preparing` | Stripe | |
| `ORD-000146` | `ReadyForShipment` | COD | |
| `ORD-000147` | `Shipped` | Stripe | shipment with tracking events |
| `ORD-000148` | `Shipped` | COD | |
| `ORD-000149` | `Delivered` | Stripe | carries `FLAT15` |
| `ORD-000150` | `Delivered` | COD | paid on remittance, after delivery |
| `ORD-000151` | `Delivered` | Stripe | payment `partially_refunded` |
| `ORD-000152` | `Cancelled` | COD | |
| `ORD-000153` | `Returned` | Stripe | carries `TOOLDEAL` |
| `ORD-000154` | `Refunded` | COD | payment `refunded` |

Serials are stable *within* a run but not across one — re-seeding renumbers
from the start of the pinned block. The shape is the durable claim, not the
number; query `User::where('email', 'customer@example.com')->first()->orders`
if the exact serials matter.

`admin@example.com` keeps 4 orders of its own. Not for the panel, which reads
every order regardless of owner, but so `/account/orders` shows something
while signed in as staff.

**No coupon code repeats within one account.** `CouponFactory` randomizes
`usage_limit_per_customer` per seed run, and a repeated code was refused by
`RedeemCoupon` on a run where `FLAT15` came up as 1 — which, because the
coupon is applied before checkout, killed the whole order and silently cost a
pinned status. Distinct codes remove the dependency on a randomized limit
rather than pinning it.

Every `PaymentStatus` and `ShipmentStatus` case appears at least once,
including the two least likely to occur by chance: `Failed` (a payment
that failed and, for 2 of them, recovered to `Paid` — a legal edge easy to
assume impossible) and `PartiallyRefunded` (2 single partial refunds, 2
stacked pairs that together still fit under the payment total — the only
accumulating transition in the enum).

**Guest orders**: 23 of 158, `user_id` null throughout — this is what
makes the public order-tracking page (order number **and** email, per
CLAUDE.md) demonstrable at all, since sequential numbers alone would
enumerate every customer's address.

**Cash on delivery**: 64 of 158. COD orders skip `AwaitingPayment`/`Paid`
entirely (`New => Confirmed` directly, the edge that case exists in
`OrderStatus` for) and open their payment only after `Delivered`, marked
paid on remittance — never before, since nothing was actually collected
until the courier did.

**Coupons redeemed**: 23 orders. `ONEUSEONLY` (the `total_usage_limit: 1`
coupon `DemoCouponSeeder` left deliberately unredeemed) now has its one
redemption — a further checkout against it demonstrates
`CouponNotApplicableException` live. `WELCOME10` (8), `FLAT15` (6), and
`TOOLDEAL` (4, product-scoped to 3 products chosen randomly at seed time —
see the coupon table below for how to read the current set live) account
for the rest. `SUMMER20` and `WINTER25` are never redeemed here — their
windows (expired, scheduled) make that impossible; they exist to
demonstrate the boundary on the coupon fixture side, not to be redeemed.

**Pull-up examples for a live demo**, verified against this seed run —
re-seeding reshuffles which orders land where, so treat these as "this
shape exists," not as durable serials:

| State | Order | Notes |
|---|---|---|
| Partially refunded payment | `ORD-000035` | one of 4 `PartiallyRefunded` payments this run produced |
| Failed payment | `ORD-000006` | `Pending => Failed`; some, not this one specifically, recover to `Paid` |
| Fully refunded, order and payment both `Refunded` | `ORD-000022` | the clean case — money collected, then given back in full |
| Guest, delivered | `ORD-000012` | for the public tracking page demo — needs this serial **and** its email, never the serial alone |

No `PartiallyRefunded` payment happened to land on a COD order this run —
the 4 partial refunds are picked randomly from whatever's `Paid` when
`DemoOrderSeeder` reaches that step, not filtered by payment method, so
which specific orders they land on varies run to run. The *state* — a COD
order with a partially refunded payment — is legal and reachable; this
particular seed just didn't produce one. Query live if a demo specifically
needs that combination:

```php
Order::where('payment_method', 'cash_on_delivery')
    ->whereHas('payment', fn ($q) => $q->where('status', 'partially_refunded'))
    ->first();
```

## Product images — done

The catalogue fixtures were authored with placeholder `product_images.path`
values on the assumption real files would be added later; nothing did until
`demo:fetch-images` (`docs/how-to/seed-the-database.md`, "Product images").
**All 182 rows now have a real file on disk**, sourced from Pexels in one
run (0 failures) — 159 distinct photos, 23 rows legitimately sharing a
generic category photo where Pexels had nothing more specific (e.g. several
power-drill products sharing one drill-in-use photo).

Verified by more than "the command said success": duplicate-file hashing
found the 23 legitimate re-uses and nothing else, and every duplicate
cluster plus a spread of singles across categories was opened and confirmed
to be a real, on-topic photo — not the corrupted-placeholder problem an
earlier attempt with a different source had (see the command's own
docblock for that history; worth reading before reaching for a scraper
instead of a licensed photo API here again).

If `product_images` ever grows past 182 (a fixture batch added later, say),
re-run `demo:fetch-images` — it only touches rows still pointing at a
missing file, so it is safe to run again at any time.

## Product and variation states

Every state `schema/fixture-format.md`'s coverage matrix requires, with the
SKU that demonstrates it.

| State | Product / variation | Notes |
|---|---|---|
| Out of stock | `PWR-0006`, `PWR-0009`, `ELC-0015`, `ELC-0023`, `WRK-0004-45` | `current_quantity = 0` on an otherwise-orderable variation — the "add to cart" refusal path, not a hidden product |
| Exactly one left | `PWR-0011`, `PWR-0012-GRY`, `HND-0006`, `HND-0014`, `KIT-0006`, `CLW-0022-M`, `SPT-0011` | the quantity-validation boundary |
| Discount active | `ELC-0002` (349.00 → 299.00), `CLM-0004`, `KIT-0002`, 20 others | `discount_starts_at`/`ends_at` straddle now |
| Discount expired | `PWR-0008` (Angle Grinder) | window ended 2 days before seeding — proves the window is honoured, not just the column |
| Discount scheduled | `KIT-0011` (Chef Knife, 44.90 → 37.90) | starts in 5 days — proves the *other* edge |
| 1 variation | 130 of 169 products | the common case |
| 5+ variations | `CLM-0001` (Classic Crew Neck T-Shirt) — 5 | size × colour combinations |
| Unavailable product | `CLM-0016`, `CLW-0021`, `HND-0008`, `HOM-0013`, `KIT-0015`, `PWR-0014`, `SPT-0006` | `is_available: false` — excluded from the storefront entirely, distinct from a merely out-of-stock variation |
| No images | same 7 products above | every unavailable product in this set also has no gallery, so one SKU demonstrates both — see "Known coincidence" below |
| Name ≥ 90 characters | `PWR-0010` (91 chars) — "18V Cordless 4-Piece Combo Kit — Drill, Driver, Grinder, Sander, for a Full Home Renovation" | card-layout stress test |
| `min_order_quantity > 1` | `HND-0007` (3), `KIT-0005` (2), `PWR-0017` (2), `WRK-0002` (2), `WRK-0008` (2) | quantity-floor validation |
| Zero variable attributes | 48 of 169 products | single-SKU products with `"attributes": []` — no size/colour picker on the product page |

### These stock states are fragile, and something now protects them

The "out of stock" and "exactly one left" rows above are not durable facts
about the catalogue — they are stock levels, and any seeder that places an
order can consume them. `TransitionOrderStatus` on `=> Shipped` composes
`CompleteSale`, which moves stock from reserved to sold and permanently
drops `current_quantity`; `CreateOrder` reserves at creation. So a
transactional seeder sampling order lines at random destroys exactly the
states this page exists to document, and does it silently — the seeder
succeeds, the orders look right, and the page is wrong.

`database/fixtures/reference/protected-skus.json` is the machine-readable
form of that constraint, and `App\Support\ProtectedSkus` is the guard.
A seeder calls `assertSelectable()` on every line before building a cart;
it throws rather than returning false, so a protected line cannot be
quietly skipped into a set smaller than its own distribution claims.

The `min_order_quantity` products are listed in that file too, under a
section that is explicitly **not** an exclusion — they belong in seeded
orders, and the floor is recorded so a seeder honours it instead of
tripping over `AddToCart`'s refusal.

If a SKU here changes, change it there in the same pass. The JSON file is
the one a machine reads; this page is the one a person reads.

### Known coincidence, not a rule

The 7 unavailable products happen to be exactly the 7 no-image products.
That's incidental to how this batch was authored (an unavailable product was
a natural place to also skip photography), **not** a constraint the schema
or any Action enforces — an available product with no images, and an
unavailable product with a full gallery, are both legal and neither is
seeded. Don't demo "unavailable implies no images" as a rule; it isn't one.

## Variation gallery — the two cases worth showing side by side

`ADR-0013`'s whole point: a variation's gallery is an ordered *subset* of the
product's images, not a 1:1 pointer. These two SKUs prove it in opposite
directions.

**One image, three variations** — `PWR-0012` (Cordless Screwdriver Compact
3.6V). All three colourways (`BLK`, `YEL`, `GRY`) share the single `main`
photo; nobody re-shot the product per colour.

**One variation, two images** — `PWR-0001` (18V Cordless Drill Driver).
`PWR-0001-BLK` (the default) carries both `main` and `side`; the sibling
`PWR-0001-RED` carries only `side` — proving a variation's own gallery both
can hold more than one image, and needn't hold the product's main shot at
all.

Query to reproduce either, for a live demo:

```php
$p = App\Models\Product::where('sku', 'PWR-0012')->first();
foreach ($p->productVariations as $v) {
    echo $v->sku, ': ', $v->images()->pluck('path')->implode(', '), "\n";
}
```

Set-wide: 20 variations carry ≥2 images in their own gallery; 39 product
images are attached to ≥2 variations. Neither is a special case authored
once — it's the default shape a shared "main" photo produces across a
catalogue this size.

## Variation-level measurement override

`ResolveVariationMeasurements`'s per-axis inheritance (`explanation` in
`write-rules/product.md`), demonstrated: `PWR-0004-BARE`, `PWR-0010-5AH`,
and `ELC-0010-BT` each override `weight_g` alone and leave `length_mm`/
`width_mm`/`height_mm` `null` — inheriting the product's dimensions exactly,
because a bare-tool or higher-capacity-battery variant is heavier without
being a different physical size. No seeded variation overrides a dimension
axis; that path is covered by `ResolveVariationMeasurementsTest`'s unit
tests instead, not by fixture data.

## Default variation, always exactly one

Every one of the 169 products has exactly one `is_default = true`
variation — verified by count, not spot-checked. Most are the first
variation in the fixture document, auto-promoted by `AddProductVariation`;
a few (e.g. `PWR-0001-BLK`) mark `is_default: true` explicitly to make the
override visible in the fixture itself, per `fixture-format.md`'s own
convention.

## Coupons — one per required state

| Code | Type | Scope | Value | Notes |
|---|---|---|---|---|
| `WELCOME10` | percentage | entire order | 10% | active, no window — 8 redemptions |
| `SUMMER20` | percentage | entire order | 20% | **expired** — window ended before seeding; never redeemed, and never can be |
| `WINTER25` | percentage | entire order | 25% | **scheduled** — window starts after seeding; never redeemed, and never can be |
| `FLAT15` | fixed | entire order | 15.00 | active, no window — 6 redemptions |
| `TOOLDEAL` | percentage | products | 15% | scoped to 3 random products, re-rolled every seed run — read live via `Coupon::where('code','TOOLDEAL')->first()->products` rather than trusting a SKU list here — 4 redemptions |
| `ONEUSEONLY` | fixed | entire order | 10.00 | `total_usage_limit: 1` — **redeemed exactly once** by `DemoOrderSeeder`. A further checkout attempt against it now demonstrates `CouponNotApplicableException` live, closing the gap `DemoCouponSeeder`'s own docblock left open. |

`minimum_order_value` on every coupon is a `CouponFactory` default and is
**randomized per seed run** — do not quote a specific minimum from a past
run; read it live if a demo needs the exact figure.

`coupons.times_used` does not exist as a column — `RedeemCoupon` counts
real `coupon_redemptions` rows instead. `write-rules/order.md` has the
fuller account of why.

## Reviews

~126 reviews, drawn only from `DemoOrderSeeder`'s delivered orders — every
review's product is a real line item on a real order belonging to the
reviewing customer, enforced by `CreateProductReview` itself, not merely
by how the fixture was written.

**The count is adaptive, not fixed**, which is why this section says "~".
`CreateProductReview` enforces one review per reviewer per product, so the
ceiling is the number of unique (reviewer, product) pairs across delivered
orders — and that moves substantially between runs (137, 123, 107 and 149 on
four consecutive seeds) because which orders reach `Delivered` is shuffled.
The seeder takes 85% of whatever is available, capped at `TOTAL_REVIEWS`, and
scales the rating weights to match. The 15% left unreviewed is deliberate: a
demo where every delivered line item already has a review has nothing to
point at for the "write a review" path.

Rating counts therefore vary with the total; the *shape* is fixed, weighted
heavily to 4 and 5 with a real tail, because a uniform spread would put every
product's average near 3 and make sort-by-rating meaningless. On the run this
page was verified against: 5★ 44, 4★ 35, 3★ 19, 2★ 11, 1★ 6.

Review body text lives in `database/fixtures/reference/review-bodies.json`
(45 bodies across the five ratings), not in the seeder. Every body at 4 and
below names something specific that went wrong — a late delivery, fiddly
assembly, a colour that did not match the photo — because a moderation queue
full of vague praise proves nothing about the moderation screen.

88 approved (via `ApproveProductReview`), 38 left pending — a populated
moderation queue, not an empty one, and the approved set deliberately
includes low ratings rather than only the flattering ones: `ELC-0002` (a
5-star example), `CLM-0019` (an approved 1-star). `KIT-0009` has a
pending review, for the moderation-queue screen.

## Articles

24 articles across 4 fixture batches (`buying-guides`, `product-news`,
`how-to`, `company`), independent of the order/review pass — articles
reference products by slug, which only needs the catalogue.

| `ArticleStatus` | Count |
|---|---:|
| `Published` | 16 |
| `Draft` | 4 |
| `Scheduled` | 3 |
| `Archived` | 1 |

`Scheduled` has no future `published_at` to point at — the fixture format
carries no such field, and `PublishArticle` only stamps `published_at` the
first time an article reaches `Published`; a scheduled article's is simply
`null` until then. `how-much-torque-do-you-need` is a scheduled example;
`how-to-store-winter-duvet-summer` is the one archived article, reached by
the loader's `Draft => Published => Archived` detour since the enum has no
direct edge into `Archived`.

15 of 24 reference at least one product via `related_products`; 9
reference none. `ContentReferenceSeeder`'s tag vocabulary was extended in
this pass to cover the general-marketplace catalogue (`clothing`,
`electronics`, `kitchen`, `home`, `sports`, `beauty`, `workwear`,
`gift-guides`, `seasonal`) — added alongside the original hardware-store
tags (`power-tools`, `hand-tools`, `garden`, `cordless`), not replacing
them.

## Carts and wishlists

44 carts, 8 of them guest carts (`session_id` only, no `user_id`) — the
`expires_at` split is the thing to point at: every guest cart carries a real
future timestamp, every registered-customer cart reads `null`, both written
through `TouchCartExpiry` rather than a literal fixture date. Confirm live:

```php
Cart::whereNotNull('user_id')->first()->expires_at;  // null
Cart::whereNull('user_id')->first()->expires_at;      // a real future Carbon instant
```

62 wishlist items across ~20% of customers — `UNIQUE(user_id, product_id)`
is the only invariant, enforced by the database directly.

## What is deliberately *not* in the JSON fixture set

Scoped to what is **authored as JSON**, not to what ends up in the seeded
database — orders, payments, shipments and reviews are all present after a
full seed, they are simply not written by hand.

- **No orders, payments, shipments, or reviews as fixture documents.**
  ADR-0003: transactional data is produced by running the real Actions in
  sequence (`CreateOrder` → `RecordPayment` → `TransitionPaymentStatus` →
  `TransitionOrderStatus` → `CreateShipment` → `TransitionShipmentStatus`),
  never authored as JSON. `DemoOrderSeeder` and `DemoReviewSeeder` are what
  produce them, and the counts above are the result.

  The one thing those seeders write directly rather than through an Action
  is **timestamps** — no Action takes a date, so every order, payment,
  shipment and review is back-dated afterward with `saveQuietly()`. That is
  correct: the thing being changed is a clock, not domain state.
- **No Stripe objects.** Every `stripe_payment_intent_id` is null after a
  seed. Seeding is offline and deterministic (ADR-0003), so the payment rows
  are real domain state with no external counterpart. `demo:stripe-payments`
  is the opt-in command that opens real test intents against them; see
  `how-to/seed-the-database.md`, "Real Stripe intents".
- **No image files *in the fixtures*.** Every `path` points at `demo/*.jpg`
  and the fixture format explicitly does not require the file to exist
  (`fixture-format.md` rule 11). The files themselves were added later by
  `demo:fetch-images` and **are committed** — see "Product images — done"
  above. A row whose file is missing falls back to
  `ResolveVariationImage::urlOrDefault()` rather than showing a broken
  image icon.
- **No products at exactly the discount-boundary edge** (`discount_price`
  equal to `regular_price`) — that case is refused at validation time
  (`fixtures:validate` requires strictly less), so it cannot exist in
  loaded data by construction. Demo the refusal by trying to author one,
  not by finding one in the catalogue.

## Bugs this dataset caught, fixed alongside it

Building this catalogue at real scale surfaced two defects invisible at the
scale earlier fixtures ran at. Recorded here because a presenter walking
through "what we found while seeding" is a legitimate part of the story:

- **`FixtureLoader` silently dropped `attributes`.** A product's
  `"attributes": ["colour", "size"]` field named which axes it varies by,
  but the loader stripped that key out to build the `products` insert and
  never used it again — `attribute_product` stayed empty for every
  fixture-loaded product, for every fixture ever loaded before this catalogue.
  Nothing caught it because nothing else in `app/` reads that pivot yet.
  Fixed in `FixtureLoader::attachAttributes()`; verify with
  `Product::where('sku', 'CLM-0001')->first()->attributes()->pluck('slug')`
  → `colour, size`.
- **`fixtures:validate` never checked string column lengths**, despite
  `SKELETON.md` promising it does. Caught by writing the ≥90-character name
  case for real: `CreateProduct` failed mid-transaction with a raw
  `Data too long for column` SQL error instead of a clean validator report.
  Fixed in `ValidateFixtures::assertLengths()`.

Both are also in `CHANGELOG.md`, in full, with the exact commits.
