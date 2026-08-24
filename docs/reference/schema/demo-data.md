# Demo data — what exists and where to find it

A lookup table for a live demo or a presentation: every notable state in the
seeded catalogue, with the exact SKU to pull up. Everything below was
verified against a real seeded database — not the JSON fixture shape, the
actual rows — on 2026-08-24. Re-seeding on the current fixture set should
reproduce all of it; if a number here stops matching, the fixture set moved
and this page is what's stale.

## Load it

```bash
docker compose exec app php artisan migrate:fresh --seed
docker compose exec app php artisan db:seed --class=DemoSeeder
docker compose exec app php artisan db:seed --class=DemoCustomerSeeder
docker compose exec app php artisan db:seed --class=DemoCartSeeder
docker compose exec app php artisan db:seed --class=DemoCouponSeeder
docker compose exec app php artisan db:seed --class=DemoWishlistSeeder
```

`docs/how-to/seed-the-database.md` is the full reference for these commands
and their run-order constraints.

## Scale, at a glance

| Table | Count |
|---|---|
| `products` | 169 |
| `product_variations` | 219 |
| `product_images` | 182 |
| `product_categories` | 173 (122 hold at least one product) |
| `brands` | 34 (33 used) |
| `attributes` / `attribute_values` | 9 / 55 |
| customers (`DemoCustomerSeeder`) | 100 |
| `carts` | 44 (8 guest) |
| `cart_items` | 116 |
| `wishlist_items` | 62 |
| `coupons` | 6 |

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
| `WELCOME10` | percentage | entire order | 10% | active, no window |
| `SUMMER20` | percentage | entire order | 20% | **expired** — window ended before seeding |
| `WINTER25` | percentage | entire order | 25% | **scheduled** — window starts after seeding |
| `FLAT15` | fixed | entire order | 15.00 | active, no window |
| `TOOLDEAL` | percentage | products | 15% | scoped to `CLM-0010`, `HOM-0010`, `KIT-0007` — the only coupon that refuses on an out-of-scope cart |
| `ONEUSEONLY` | fixed | entire order | 10.00 | `total_usage_limit: 1`, never redeemed — the narrow-limit boundary, **not** an exhausted coupon; see the note below |

**`ONEUSEONLY` cannot demo "coupon already used"** — `coupons.times_used`
doesn't exist as a column (`RedeemCoupon` counts real `coupon_redemptions`
rows instead), and a redemption needs a real `order_id`, which orders are
out of scope for this seed. To show a coupon actually refusing at its
cap, redeem it once through `RedeemCoupon` live during the demo, or wait
for whichever session seeds orders. `schema/open-schema-questions.md`
and `write-rules/order.md` have the fuller account.

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

## What is deliberately *not* in this dataset

- **No orders, payments, shipments, or reviews.** ADR-0003: transactional
  data is produced by running the real Actions in sequence
  (`CreateOrder` → `RecordPayment` → `TransitionPaymentStatus` →
  `TransitionOrderStatus` → `CreateShipment` → `TransitionShipmentStatus`),
  never authored as JSON. `misc/sonnet-phase1-data-brief.md` has the
  verified walk-through and the `CompleteSale`/reserved-stock trap in it.
- **No image files.** Every `path` in the fixtures points at
  `demo/*.jpg`, resolved through `Storage::disk('public')` to
  `storage/app/public/demo/…` — deliberately absent per
  `fixture-format.md` rule 11 ("the file does not have to exist"). A
  product page will show its placeholder fallback
  (`ResolveVariationImage::urlOrDefault()`), not a broken image icon.
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
