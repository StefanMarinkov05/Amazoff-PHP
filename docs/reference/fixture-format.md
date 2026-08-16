# Demo fixture format

The JSON shape `FixtureLoader` consumes and `fixtures:validate` checks.
One document per product — the aggregate root — carrying its own
variations, images, and specifications. No integer IDs anywhere; cross-
references use slugs, resolved by the loader.

ADR-0003 covers why: fixtures are nested by aggregate rather than one file
per table, because a product's variations have no meaning without it, and
foreign keys are a property of the loader rather than of the file.

## Why money and dates are strings

`"189.90"`, never `189.90`. JSON numbers are floats and CLAUDE.md forbids
float for money — the loader passes the string straight into
`CreateProduct`, which casts it `decimal:2`.

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
  "weight": "1.80",
  "dimensions": "35x28x10 cm",
  "is_available": true,
  "is_featured": false,
  "seo_title": "18V Cordless Drill Driver | Example Shop",
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
      "weight": null,
      "is_available": true,
      "image_key": "main",
      "initial_quantity": 42,
      "attribute_values": { "colour": "Black" }
    },
    {
      "sku": "PWR-DRL-18V-001-RED",
      "price": "199.90",
      "discount_price": null,
      "weight": null,
      "is_available": true,
      "image_key": "side",
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
| `category`, `brand` | slugs, resolved against `product_categories.slug` / `brands.slug` |
| `regular_price`, `discount_price`, `weight` | strings; `discount_price` must be `< regular_price` or omitted |
| `discount_starts_at`, `discount_ends_at` | relative offset strings or omitted; `ends_at` must be after `starts_at` |
| `attributes` | list of attribute slugs this product varies by — must match the keys used in every variation's `attribute_values` |
| `images[].key` | local to this document only; **not** a database key. Exists so a variation can reference an image before either has an ID |
| `images[].is_main` | exactly one `true` per document, or none — `fixtures:validate` rejects two |
| `variations` | at least one, required by `CreateProduct` |
| `variations[].price` | `null` means inherit the product's `regular_price` |
| `variations[].image_key` | must match an `images[].key` in the same document, or be omitted |
| `variations[].initial_quantity` | opening stock; not a column, becomes an `InitialStock` movement via `AddProductVariation` |
| `variations[].attribute_values` | `{ attribute_slug: value_slug }`, one entry per entry in the product's `attributes` list |

## What the loader does with one document

1. Resolve `category` and `brand` slugs to IDs.
2. Call `CreateProduct` with the product columns and the variations array
   (after resolving each variation's `image_key` to a real `product_images.id`,
   which means images are inserted **before** `CreateProduct` runs, directly
   — see below).
3. Insert `specifications` directly (`ProductSpecificationsRelationManager`
   uses plain CRUD; the loader does too — no invariant to protect).
4. Resolve `attribute_values` slugs and attach the pivot rows.

Images are the one exception to "everything goes through the Action":
`AddProductVariation` takes `image_id` as a plain column, so the loader
inserts the product's images directly first, builds a `key → id` map, then
resolves each variation's `image_key` before calling the Action. This
mirrors what `AddProductImage`/`SetMainProductImage` would do if the loader
called them instead — a fixture-loading follow-up can switch to the real
Actions once cascading `is_main` matters for seeded data.

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

## Top-level file shape

One file, four top-level arrays. `products` is a list of the document above;
the other three are their own flat shapes, below. Order matters — `users` and
`products` must exist before `coupons` (scope references) and `carts`
(product/variation references) resolve.

```json
{
  "users": [ /* shape below */ ],
  "products": [ /* the document above, repeated */ ],
  "coupons": [ /* shape below */ ],
  "carts": [ /* shape below */ ]
}
```

### `users`

Plain Eloquent, like `UserFactory` — no invariant, no Action. `role` is one of
the three seeded roles or `null` for a plain customer (see
`reference/permissions.md`); never invent a role name.

```json
{
  "email": "ivan.petrov@example.com",
  "first_name": "Ivan",
  "last_name": "Petrov",
  "role": null
}
```

### `coupons`

Single-table (ADR-0007) — seeded directly, no Action. `scope_targets` is only
meaningful when `scope` is `products` or `categories`; omit it for
`entire_order`. `type: percentage` requires `value <= 100`
(`chk_coupons_percentage_within_bounds`).

```json
{
  "code": "SUMMER20",
  "name": "Summer sale",
  "type": "percentage",
  "scope": "entire_order",
  "value": "20.00",
  "max_discount_amount": "50.00",
  "minimum_order_value": "100.00",
  "starts_at": "-14 days",
  "ends_at": "+14 days",
  "total_usage_limit": 500,
  "usage_limit_per_customer": 1,
  "is_active": true
}
```

Cover, by rule: one active with no window, one expired (`ends_at` in the
past), one scheduled (`starts_at` in the future), one `type: fixed`, one
`scope: products` with `scope_targets` naming 2–3 product slugs, one at
`total_usage_limit` reached (`times_used` = limit — direct-write only, no
Action enforces this yet).

### `carts`

References resolve against `users.email` and `products[].slug` +
`variations[].sku` — **generate this array after `products`, in the same
pass or a second one**, never against invented IDs. `user_id` omitted means a
guest cart (`session_id` only).

```json
{
  "user_email": "ivan.petrov@example.com",
  "coupon_code": null,
  "expires_at": "+3 days",
  "items": [
    { "product_slug": "cordless-drill-18v", "variation_sku": "PWR-DRL-18V-001-BLK", "quantity": 1 }
  ]
}
```

One `variation_sku` per cart is enough per line —
`UNIQUE(cart_id, product_variation_id)` forbids two lines for the same
variation, sum the quantity instead.

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
| `users` (customers) | 50–150 | enough for pagination and cart/wishlist variety; more buys nothing architecturally |
| `coupons` | 8–12 | one per state in the coverage list above, not a count to pad |
| `carts` | ~30–40% of customers | most customers don't have an active cart; a cart on every account is unrealistic |
| `wishlist_items` | ~20% of customers, 1–5 each | same reasoning |
| `carriers` | 2 | Econt, Speedy — fixed, not generated |
| orders / payments / shipments | **0 for now** | Phase 2 (ADR-0003) — produced by calling `CreateOrder` etc. in a loop once slices 5–7 exist, never authored as JSON |

The rule underneath the table: **catalogue volume should scale to 500;
everything else should scale to what's needed to demonstrate a state, not to
the catalogue.** 500 coupons or 500 customers proves nothing §37 grades on and
costs LLM tokens for no benefit.
