# Demo fixture format

The JSON shape `FixtureLoader` consumes and `fixtures:validate` checks.
One document per product — the aggregate root — carrying its own
variations, images, and specifications. No integer IDs anywhere; cross-
references use slugs, resolved by the loader.

ADR-0003 covers why: fixtures are nested by aggregate rather than one file
per table, because a product's variations have no meaning without it, and
foreign keys are a property of the loader rather than of the file.

**One file per batch, named for its category** — `power-tools.json`,
`garden.json`. A file holds either a single product object or an array of
them, and both the loader and `fixtures:validate` accept either, so a
generated batch needs no splitting. The granularity is a workflow choice
rather than a format one: one file per product gives marginally better diffs,
one file for all 300 makes every diff useless and every concurrent addition a
conflict, and the batch is what an authoring pass actually produces.

## Why decimals and dates are strings

`"189.90"`, never `189.90`. JSON numbers are IEEE-754 floats, and a value
that round-trips through one has already lost the guarantee the `decimal`
column was chosen to provide. The loader passes the string straight into
`CreateProduct`, which casts it `decimal:2`.

This covers every **decimal** column: `regular_price`, `discount_price`,
and `vat_rate`. Money is the one CLAUDE.md forbids float for outright, and
`vat_rate` shares the type.

`weight_g` and the three `_mm` dimensions are **integers, not strings** —
they are integer columns, storing whole grams and whole millimetres, so
there is no decimal to lose. The rule is about the column type, not about
the field's meaning: decimals are strings, integers are integers.

Dates are relative offsets — `"-7 days"`, `"+30 days"` — never absolute.
An absolute date goes stale: the "discount active" branch silently stops
being covered a month after the fixture was written, and nothing reports
it. The loader resolves the offset against `now()` at load time.

## Document shape

```json
{
  "slug": "cordless-drill-18v",
  "sku": "PWR-DRL-18V-001",
  "category": "power-tools",
  "brand": "boschtech",
  "name": "18V Cordless Drill Driver",
  "short_description": "Compact drill for everyday jobs.",
  "description": "Full HTML/markdown body...",
  "regular_price": "189.90",
  "discount_price": "149.90",
  "discount_starts_at": "-7 days",
  "discount_ends_at": "+7 days",
  "vat_rate": "20.00",
  "min_order_quantity": 1,
  "weight_g": 1800,
  "length_mm": 350,
  "width_mm": 280,
  "height_mm": 100,
  "is_available": true,
  "is_featured": false,
  "seo_title": "18V Cordless Drill Driver | Amazoff",
  "seo_description": "Buy the 18V Cordless Drill Driver online.",
  "attributes": ["colour"],

  "images": [
    { "key": "main", "path": "drill-main.jpg", "alt_text": "Drill, front view", "is_main": true, "sort_order": 0 },
    { "key": "side", "path": "drill-side.jpg", "alt_text": "Drill, side view", "is_main": false, "sort_order": 1 }
  ],

  "specifications": [
    { "name": "Voltage", "value": "18V", "sort_order": 0 },
    { "name": "Battery", "value": "2x 2.0Ah Li-ion", "sort_order": 1 }
  ],

  "variations": [
    {
      "sku": "PWR-DRL-18V-001-BLK",
      "price": null,
      "discount_price": null,
      "weight_g": null,
      "is_available": true,
      "images": ["main", "side"],
      "initial_quantity": 42,
      "attribute_values": { "colour": "Black" }
    },
    {
      "sku": "PWR-DRL-18V-001-RED",
      "price": "199.90",
      "discount_price": null,
      "weight_g": null,
      "is_available": true,
      "images": ["side"],
      "initial_quantity": 0,
      "attribute_values": { "colour": "Red" }
    }
  ]
}
```

## Field notes

| Field | Rule |
|---|---|
| `slug`, `sku` (product) | unique within the fixture set, checked cross-document by `fixtures:validate` |
| `category`, `brand` | slugs, resolved against `product_categories.slug` / `brands.slug`. **Any** category resolves, leaf or not — see "Products on non-leaf categories" below |
| `regular_price`, `discount_price`, `vat_rate` | decimal **strings**; `discount_price` must be `< regular_price` or omitted |
| `weight_g` | whole grams, a JSON **integer** — not a string, because it is an integer column, not a decimal one |
| `length_mm`, `width_mm`, `height_mm` | whole millimetres, JSON integers; all three or none |
| `dimension_display_unit`, `weight_display_unit` | omit — they default to `cm` and `kg` and record only how the panel shows the number back. Storage is always grams and millimetres, whatever they say |
| `discount_starts_at`, `discount_ends_at` | relative offset strings or omitted; `ends_at` must be after `starts_at` |
| `attributes` | list of attribute slugs this product varies by — must match the keys used in every variation's `attribute_values` |
| `images[].key` | local to this document only; **not** a database key. Exists so a variation can reference an image before either has an ID |
| `images[].is_main` | exactly one `true` per document, or none — `fixtures:validate` rejects two |
| `variations` | at least one, required by `CreateProduct` |
| `variations[].price` | `null` means inherit the product's `regular_price` |
| `variations[].weight_g` | whole grams, JSON integer; `null` inherits the product's weight. A variation may genuinely weigh more than its siblings — a book's hardcover edition — which is why this exists at variation level and not only on the product |
| `variations[].length_mm`, `width_mm`, `height_mm` | whole millimetres, JSON integers; `null` inherits the product's, **per axis** — a hardcover overrides `height_mm` alone and inherits the other two rather than restating them. No per-variation dimension unit: a variation's dimensions are entered and shown in the *product's* `dimension_display_unit`, never its own. `ResolveVariationMeasurements` is what performs the inheritance |
| `variations[].is_default` | `true` on at most one variation per product, or omit entirely. The first variation in the array becomes default automatically if none is marked — see below |
| `variations[].images` | ordered list of `images[].key` values from the same document; array order becomes gallery `position`. Omit for a variation that inherits the product's main image |
| `variations[].initial_quantity` | opening stock; not a column, becomes an `InitialStock` movement via `AddProductVariation` |
| `variations[].attribute_values` | `{ attribute_slug: value_slug }`, one entry per entry in the product's `attributes` list |

## What the loader does with one document

1. Resolve `category` and `brand` slugs to IDs.
2. Call `CreateProduct` with the product columns and the variations array.
   Images are inserted **before** this runs, directly — see below — so the
   `key → id` map exists by the time a variation's gallery is resolved.
3. For each variation carrying `images`, resolve its keys against that map
   and call `SetVariationImages` — the pivot carries an ordering no column
   on `product_variations` can express.
4. Insert `specifications` directly (`ProductSpecificationsRelationManager`
   uses plain CRUD; the loader does too — no invariant to protect).
5. Resolve `attribute_values` slugs and attach the pivot rows.

Images are the one exception to "everything goes through the Action": the
loader inserts the product's images directly first and builds a `key → id`
map. This mirrors what `AddProductImage`/`SetMainProductImage` would do if
the loader called them instead — a fixture-loading follow-up can switch to
the real Actions once cascading `is_main` matters for seeded data.

`variations[].images` replaced a singular `image_key` when ADR-0013 dropped
`product_variations.image_id`. A variation now shows an ordered subset of the
product's images rather than pointing at one, so the fixture field is a list
and the loader goes through `SetVariationImages` rather than setting a column.

### Default variation

`is_default` is a plain column, passed straight through to `AddProductVariation`
like any other — no separate loader step, unlike the gallery. The Action
itself decides what happens: the **first** variation in `document.variations`
becomes default whether or not the fixture marks one, the same way
`AddProductImage` promotes a product's first image to main. Marking a later
one `"is_default": true` overrides that. Marking two is not a fixture error
the validator catches — the second write simply wins, since
`SetDefaultVariation` demotes every sibling on each promotion — so keep at
most one `true` per document by convention, not by a check.

### Products on non-leaf categories

`fixtures:validate` resolves `category` with
`ProductCategory::query()->pluck('slug')` — every row, at every depth — so a
product may sit on a parent category like `clothing` as readily as on a leaf
like `clothing-men-tops-t-shirts`. Nothing refuses it, and that is
deliberate rather than an oversight.

**It matches real practice.** Large catalogues routinely carry products at
mid-depth: a gift card or a multi-pack belongs to `clothing` and to no
specific leaf under it, and forcing a `clothing-misc` leaf into the taxonomy
to satisfy a validator makes the tree worse rather than the data better. The
constraint that actually matters to a storefront is the opposite one — a
category listing has to include products from its **descendants**, not just
its own rows — and that is a query concern, not a fixture rule.

**Convention, not enforcement:** author demo products on leaves anyway,
because a listing page that walks descendants is more convincingly exercised
by a tree whose products sit at the bottom of it. If a leaf-only rule is
ever wanted it belongs in `fixtures:validate` as a check against
`children()->exists()`, and it needs a decision about the gift-card case
first.

## Coverage the demo fixture set must include

Per `misc/session-notes.md`'s state-coverage matrix — assigned by rule, not
left to the LLM authoring prose:

- out of stock (`initial_quantity: 0` on every variation)
- exactly one left (`initial_quantity: 1`)
- discount active / expired / scheduled (`discount_starts_at`/`ends_at`
  straddling, before, or after `now()`)
- 1 / 3 / 5+ variations
- `is_available: false`
- no images
- `name` ≥ 90 characters (card-layout stress)
- `min_order_quantity > 1`
- one image shared across ≥2 variations' galleries (the common case — a
  colourway's `main` shot reused on a variant that has no photo of its own)
- one variation with ≥2 images in its own gallery

## What a fixture file actually contains

**A fixture file is a product document, or an array of them. Nothing else.**
`FixtureLoader::loadProduct()` is the only entry point, and
`fixtures:validate` requires product keys on every document it reads, so a
file wrapping documents in a `{"products": [...]}` envelope fails with
`is missing required key [name]` before a row is written.

Customers, coupons, carts, and wishlists are **not fixtures** and have no
JSON shape. They are produced by seeders, from factories plus an explicit
distribution — `DemoCustomerSeeder`, `DemoCouponSeeder`, `DemoCartSeeder`,
`DemoWishlistSeeder`, all already built. ADR-0003's rule is why: content
diversity is what an LLM provides and state diversity is what rules provide,
and these four are entirely state. A customer named "Ivan Petrov" is worth
nothing over a factory-generated one; what matters is how many customers
exist, how many hold a cart, and that the coupon set covers an expired one
and a scheduled one. Those are counts and rules, which a seeder states more
reliably in PHP than a model restates in JSON.

The catalogue is the one place hand-authored content earns its cost: names,
descriptions, and per-category specification values are read by people and
are exactly what a factory cannot fake.

### Coverage the demo seeders must produce

Kept here because these are fixture-set requirements, not implementation
notes — `seed-the-database.md` covers running them; this page covers what
the resulting data must contain.

`DemoCustomerSeeder`: 100 customers, `User::factory()->count(100)->create()`,
`role: null` throughout (a customer holds no role — same reasoning
`UserSeeder`'s own customer account gives).

`DemoCartSeeder`: carts for ~35% of demo customers plus a handful of guest
carts (`session_id`, no `user_id`), against real product-variation rows —
not every customer, a cart on every account is unrealistic. Built with
direct `Cart`/`CartItem` writes rather than `CartFactory`, because that
factory's own default attaches a random fake `coupon_id` — wrong for a demo
cart that should mostly carry none. `expires_at` is set through
`TouchCartExpiry`, the same Action every real cart write goes through, not a
literal date: a registered customer's cart is always `null`, a guest cart is
`now() + config('cart.guest_ttl_hours')`. Must run after `DemoCustomerSeeder`
and the catalogue.

`DemoCouponSeeder`: one coupon per state below, not a count to pad. By rule:
one active with no window, one expired (`ends_at` in the past), one
scheduled (`starts_at` in the future), one `type: fixed`, one
`scope: products` targeting 2-3 real product rows, one at a narrow
`total_usage_limit` (1). **Not** "reached its cap" — `coupons.times_used`
does not exist (`reference/actions.md`: `RedeemCoupon` counts real
`coupon_redemptions` rows instead), and a redemption needs a real `order_id`
(`NOT NULL`, cascade-on-delete). Orders are 0-scope for this seed (below);
producing an actually-exhausted coupon needs a real order and belongs to
whichever session seeds those.

`DemoWishlistSeeder`: wishlists for ~20% of demo customers, 1-5 products
each. `UNIQUE(user_id, product_id)` is the only invariant and MySQL enforces
it directly — no Action exists or is needed. Must run after
`DemoCustomerSeeder` and the catalogue.

Run order: `DemoSeeder` (catalogue) → `DemoCustomerSeeder` →
`DemoCartSeeder`, `DemoCouponSeeder`, `DemoWishlistSeeder` (any order among
these three) — `seed-the-database.md` has the actual commands.

## Record counts

500 products is `StressSeeder` scale per ADR-0003 (~1,500 variations,
existing to prove the loader and the listing page survive volume — nobody
reads the individual rows). `DemoSeeder` is ~150–200, LLM-authored, meant to
be read. Confirm which one you're generating before handing this to another
LLM — the prose budget differs by 3×.

| Table | Count | Why |
|---|---|---|
| `products` | 500 (stress) / 150–200 (demo) | given |
| `variations` | ~3 per product (~1,500 total) | ADR-0003's own stress number |
| `product_categories` | 15–30 | a real shop's depth, not proportional to product count |
| `brands` | 20–50 | same |
| `attributes` + `attribute_values` | 5–10 attributes, 5–15 values each | fixed vocabulary, doesn't scale with catalogue size |
| `users` (customers) | 100, `DemoCustomerSeeder` | enough for pagination and cart/wishlist variety; more buys nothing architecturally |
| `coupons` | 6, `DemoCouponSeeder` | one per state in the coverage list above, not a count to pad |
| `carts` | ~35% of customers, `DemoCartSeeder` | most customers don't have an active cart; a cart on every account is unrealistic |
| `wishlist_items` | ~20% of customers, 1–5 each, `DemoWishlistSeeder` | same reasoning |
| `carriers` | 2 | Econt, Speedy — fixed, not generated |
| orders / payments / shipments | **0 for now** | Phase 2 (ADR-0003) — produced by calling `CreateOrder` etc. in a loop once slices 5–7 exist, never authored as JSON |

The rule underneath the table: **catalogue volume should scale to 500;
everything else should scale to what's needed to demonstrate a state, not to
the catalogue.** 500 coupons or 500 customers proves nothing §37 grades on and
costs LLM tokens for no benefit.
