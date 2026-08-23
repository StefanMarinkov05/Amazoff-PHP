# Fixture generation — the prompt and the skeleton

What to hand an LLM to produce catalogue fixtures, and what to check when it
hands them back. `reference/schema/fixture-format.md` is the authoritative field
reference; this file is the working prompt built from it.

## Which model, and why batches

**Sonnet, not Haiku.** The failure mode with Haiku on this task is not broken
JSON — it is *sameness*. Three hundred products need distinct names,
plausible spec values per category, and descriptions that do not all open the
same way, and that is exactly the judgment Haiku trades away. Haiku is a
reasonable choice for one narrow slice: regenerating `seo_title` and
`seo_description` for documents whose other fields already exist.

**Batch 20–25 products per call.** One product is roughly 400 tokens of JSON;
300 in one response is ~120k tokens of output, past any single reply. Batching
also keeps a bad batch cheap to discard.

**Vary the category between batches, not within.** Ten batches of "power
tools, hand tools, garden" produces ten near-identical runs. One batch per
category, with the category named in the prompt, produces a catalogue.

## Cross-batch dependencies — solved by scheme, not by the model

Four things must hold across the whole 300, not just within one batch: SKU
uniqueness, product-slug uniqueness, and every `category`/`brand`/
`attribute_values` slug resolving against a real row.

**Do not ask the model to maintain any of them.** It cannot see the previous
275 products, and a context window large enough to hold them makes each call
slower, dearer, and still unreliable. Constrain the output so collisions are
structurally impossible, then verify mechanically:

| Dependency | How it is made impossible to get wrong |
|---|---|
| SKU uniqueness | A per-batch prefix plus a sequence: `PWR-0001`, `PWR-0002`. Two batches with different prefixes cannot collide however the model numbers within them |
| Variation SKU | The product SKU plus a variant suffix: `PWR-0001-BLK`. Unique because the product SKU is |
| Slug uniqueness | Slug derives from a name the model is told to keep distinct *within* the batch; the category prefix in the name keeps batches apart |
| Category / brand slugs | Paste the exact valid list into the prompt. The model picks from a list rather than inventing one |
| Attribute value slugs | Same — paste the exact `attribute: [values]` map, and the model may use nothing else |

Everything above is then checked by `fixtures:validate`, which is the actual
guarantee. The scheme makes violations unlikely; the validator makes them
visible before a row is written.

**The valid vocabulary**, from `CatalogueReferenceSeeder` — paste the
relevant lines into each prompt:

```
categories: power-tools, drills-drivers, saws, sanders-grinders,
            hand-tools, wrenches, screwdrivers, measuring,
            garden, mowers, watering,
            workwear, gloves, footwear
brands:     boschtech, makita, dewalt, stanley, hikoki, einhell,
            gardena, husqvarna
attributes: colour  -> black, red, blue, yellow, green, grey
            size    -> s, m, l, xl, xxl
            power-source -> corded, battery, petrol, manual
            capacity     -> 2ah, 4ah, 5ah
```

Suggested SKU prefixes, one per batch: `PWR` power tools, `HND` hand tools,
`GRD` garden, `WRK` workwear.

## The state coverage matrix — assign these yourself

ADR-0003's rule: content diversity is what the model provides, state diversity
is what §37 is graded on, and rules provide it more reliably. Do not ask the
model to "make some out of stock". Decide the distribution, then instruct each
batch explicitly.

Across the whole 300, the set must contain at least:

| State | How to force it | Why §37 needs it |
|---|---|---|
| Out of stock | `initial_quantity: 0` on every variation | Criterion 5, cart validation |
| Exactly one left | `initial_quantity: 1` | Boundary for quantity validation |
| Discount active | `discount_price` set, `discount_starts_at: "-7 days"`, `discount_ends_at: "+14 days"` | Criterion 3, sorting by price |
| Discount expired | `discount_ends_at: "-1 day"` | Proves the window is honoured, not just the column |
| Discount scheduled | `discount_starts_at: "+7 days"` | Same, the other edge |
| 1 variation | one entry in `variations` | The common case |
| 5+ variations | five or more | Proves the variation UI at width |
| Inactive product | `is_available: false` | Must not appear in the storefront |
| No images at all | omit `images` | Proves the placeholder path (`ResolveVariationImage`) |
| Variation gallery differing from product main | `variations[].images` naming a non-main key | ADR-0013's whole point |
| Variation with no gallery | omit `variations[].images` | Proves inheritance of the product main image |
| Name ≥ 90 characters | write a long one | Breaks card layouts if the UI is wrong |
| `min_order_quantity` > 1 | e.g. `6` | Criterion 5 again, the other direction |

A practical split for 300: ~240 ordinary products, ~60 carrying one of the
above deliberately. Track which document covers which row.

## The prompt

Paste this, with `{CATEGORY}`, `{BRAND_SLUGS}`, and `{COUNT}` filled in.

---

You are generating seed data for a Bulgarian e-commerce demo catalogue.

Produce `{COUNT}` product documents as a JSON array. Output **only** the JSON
array — no prose, no markdown fence, no commentary.

Each document must match this shape exactly:

```json
{
  "name": "18V Cordless Drill Driver",
  "slug": "18v-cordless-drill-driver",
  "sku": "PWR-DRL-18V-001",
  "category": "{CATEGORY}",
  "brand": "one of: {BRAND_SLUGS}",
  "short_description": "One sentence, under 255 characters.",
  "description": "Two or three paragraphs of plain prose. No HTML.",
  "regular_price": "189.90",
  "discount_price": null,
  "discount_starts_at": null,
  "discount_ends_at": null,
  "vat_rate": "20.00",
  "min_order_quantity": 1,
  "weight_g": 1600,
  "length_mm": 240,
  "width_mm": 80,
  "height_mm": 210,
  "is_available": true,
  "is_featured": false,
  "seo_title": "18V Cordless Drill Driver | Amazoff",
  "seo_description": "Buy the 18V Cordless Drill Driver online. Under 255 characters.",
  "attributes": ["colour"],
  "images": [
    { "key": "main", "path": "demo/drill-main.jpg", "alt_text": "Drill, front view", "is_main": true, "sort_order": 0 },
    { "key": "side", "path": "demo/drill-side.jpg", "alt_text": "Drill, side view", "is_main": false, "sort_order": 1 }
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
      "length_mm": null,
      "width_mm": null,
      "height_mm": null,
      "is_available": true,
      "is_default": true,
      "images": ["main", "side"],
      "initial_quantity": 42,
      "attribute_values": { "colour": "black" }
    }
  ]
}
```

Rules, all of which are checked by a validator before this data is loaded:

1. **All money is a string**, never a JSON number. `"189.90"`, not `189.90`.
   `weight_g` and the three `_mm` dimensions are the opposite — plain JSON
   **integers** (whole grams, whole millimetres), because they are integer
   columns. Decimals are strings; integers are integers.
2. **`slug` and both `sku` fields are globally unique** across the entire
   catalogue, not just this batch. Prefix SKUs per category so batches cannot
   collide — use `{SKU_PREFIX}`.
3. **`discount_price` must be strictly less than `regular_price`**, or `null`.
4. **Dates are relative offset strings** — `"-7 days"`, `"+14 days"` — never
   absolute dates.
5. **Every product needs at least one variation.**
6. **At most one image may have `is_main: true`.**
7. **`variations[].images` may only name keys defined in that same document's
   `images` array.** Omit the field entirely for a variation that should
   inherit the product's main image.
8. **`attribute_values` uses slugs on both sides** — `{"colour": "black"}`,
   not `{"Colour": "Black"}`. Only use attribute slugs listed in
   `attributes`, and only value slugs that already exist in the database.
9. **No two variations of one product may carry identical `attribute_values`.**
10. `name` ≤ 100 chars, `slug` ≤ 100, `sku` ≤ 64, `short_description` ≤ 255,
    `seo_title` ≤ 100, `seo_description` ≤ 255.
11. `image.path` is a relative path only. The file does not have to exist.
12. `variations[].weight_g`/`length_mm`/`width_mm`/`height_mm` are `null`
    when a variation should inherit the product's own value — the common
    case. Set them only when a variation genuinely differs, and only the
    axes that differ: a hardcover edition is the same page size as the
    paperback and only thicker, so it overrides `height_mm` and `weight_g`
    and leaves `length_mm`/`width_mm` null. Each axis inherits on its own.
13. `variations[].is_default` is `true` on **at most one** variation, or
    omit it entirely — the first variation in the array becomes default
    automatically when none is marked.

Write in English. Prices in EUR, realistic for the Bulgarian market. Make the
products genuinely distinct from one another — different use cases, price
points, and spec sets, not one product restated fifteen times.

---

## After each batch

Save the batch as one file named for its category —
`database/fixtures/demo/power-tools.json` — and validate. No splitting step:
a fixture file holds either one product object or an array of them.

```bash
docker compose exec app php artisan fixtures:validate database/fixtures/demo
```

The validator reports every problem in the set at once. Fix and re-run before
loading anything — a half-loaded 300-product set is worse than none, because
the SKUs that did land now block a re-run.

## What not to ask a model for

**Numbers and states.** See the matrix above — assign them.

**Anything transactional.** Orders, carts, payments, shipments, reservations,
coupon redemptions and status history are not fixtures. ADR-0003 is explicit:
they are produced by running the Actions that produce them in real use, so
that seeded history is reachable the same way real history is. There is no
JSON shape for an order, deliberately.
