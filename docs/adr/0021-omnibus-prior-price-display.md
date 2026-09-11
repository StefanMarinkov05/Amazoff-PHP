# ADR-0021: Omnibus prior-price display

Status: Accepted
Date: 2026-09-10 · Deciders: Stefan Marinkov

Implements the Omnibus Directive (EU) 2019/2161 price-reduction rule that
[ADR-0019](0019-regulatory-compliance.md) §4 named as a gap: when a product
shows a reduced price, the storefront must also show the lowest price it was
sold at in the 30 days before the reduction (BG: Закон за защита на
потребителите, чл. 6б).

## Context

`products.regular_price` / `discount_price` / `discount_starts_at` /
`discount_ends_at` already exist and `ResolveProductPrice` already decides
whether a discount window is live. What was missing: any record of what a
product's price *was* over time, and the "was N / lowest recent N" line
wherever the reduction is announced.

Omnibus prior-price is **not** in the graded contract (`specification.md`
§37) — it is a "make the project real" regulatory item — so the design is
deliberately proportionate: correct enough, defensible, marked for counsel,
not gold-plated.

## Decisions

### 1. A `product_price_history` table of effective-price observations

One row per observation: `product_id`, `price` (`decimal(10,2)`),
`recorded_at`. `price` is the **effective selling price** —
`ResolveProductPrice::current()->current`, i.e. the discounted price while the
window is open, the regular price otherwise. Recording *that* rather than the
raw columns makes the prior-price query a plain `MIN(price)` with no replay of
discount schedules.

### 2. Written on price change **and** once a day

- `RecordPriceObservation` (an Action) records a row. `CreateProduct` and
  `UpdateProduct` call it after saving; it no-ops when the effective price is
  unchanged since the last row.
- `products:snapshot-prices` (a scheduled command, daily) records a row for
  **every** product, unconditionally, via `RecordProductPrices`.

The daily sweep is the load-bearing half, and the reason to have it despite
ADR-0019 saying only "through `UpdateProduct`": a scheduled discount window
opening or closing on its `discount_starts_at` / `discount_ends_at` date
changes the effective price with **no admin edit**, so a write-triggered-only
history would miss exactly the transitions Omnibus cares about. A daily
observation is also the literal meaning of "the lowest price *applied during*
the 30 days". Storage is trivial — ~169 products × ~30 days.

### 3. The look-back window and the reference date

`ResolvePriorPrice::forProduct()` returns `MIN(price)` over `recorded_at ∈
[reference − 30 days, reference)`, where `reference` is `discount_starts_at`
when set, otherwise now. When the 30-day window holds no observation, it falls
back to the single most recent row recorded before `reference` (the price
already in effect entering the window). It returns `null` when the product is
not on sale, or when there is no usable history — a product with under 30
days of history cannot honestly claim a prior price, which is the correct
outcome.

### 4. Product-level only

The prior price is computed against the **product's** price timeline, not
per-variation, even though `product_variations` can override `price` /
`discount_price`. ADR-0019 §4 already scoped it to product level; tracking
per-SKU history multiplies the table and the query for a case this catalogue
barely uses. A variation with its own deeper discount is a documented gap.

### 5. Where it shows

Wherever a reduction is announced and only when `ProductPrice::onSale` is
true: the product page (`ProductDetails`), the catalogue grid
(`ProductList`), and the home page's discounted-products section. Wording:
"Lowest price in the last 30 days: €X.XX". The catalogue grid and the home
section eager-load a 40-day slice of `priceHistory` so the page does not fan
out to one query per card; `ResolvePriorPrice` reads the loaded relation when
present.

### 6. Backfill for existing products

The migration seeds a two-point starting timeline for every existing product
— `regular_price` at 31 days ago (the assumed pre-sale price) and today's
effective price — so the line is meaningful before 30 days of the daily
snapshot have accrued. New products get their first row from `CreateProduct`.

## Consequences

- New migration `2026_09_10_100000_create_product_price_history_table.php`
  (with the backfill). New model `ProductPriceHistory` + `Product::priceHistory()`.
- New Actions `App\Actions\Catalogue\RecordPriceObservation` (per product) and
  `RecordProductPrices` (the sweep). New resolver
  `App\Support\Resolvers\ResolvePriorPrice`. New command
  `products:snapshot-prices`, scheduled daily in `routes/console.php`.
- `CreateProduct` / `UpdateProduct` gain one post-save call each (guarded by
  `wasChanged` on the price fields in `UpdateProduct`).
- `ProductDetails`, `ProductList`, `Home` gain a `priceHistory` eager-load and
  the display. `product-details`, `product-list`, `home/product-section`
  blades gain the line.
- No pruning of `product_price_history` — at this scale it is a non-issue;
  add a `products:prune-price-history` if the catalogue grows an order of
  magnitude.

## What stays a gap after this pass

- **Delivery of the exact ЗЗП чл. 6б wording** and the placement rules —
  counsel review (`regulatory-compliance.md`).
- **Variation-level overrides** are not tracked (decision 4).
- **A stricter reading** — using the prior price *as* the struck-through
  reference and suppressing the "−X%" badge when there was no genuine
  reduction versus recent history — is left as a possible enhancement. This
  pass shows the prior-price line alongside the existing struck `regular`
  price rather than replacing it.
- The backfill's "regular price 31 days ago" is an assumption, not a
  measurement (decision 6).

## Alternatives rejected

**Write-triggered history only** (ADR-0019 §4's literal wording). Misses
scheduled discount-window transitions that change the effective price with no
admin edit (decision 2).

**Snapshot the raw columns and replay discount schedules at query time.**
Correct, but the replay logic (windows crossing snapshot boundaries) is
fiddly for a non-graded feature; recording the effective price directly makes
the query a one-liner.

**Per-variation price history.** Speculative generality for a catalogue whose
discounts are almost all product-level (decision 4).

**Write the history from the price resolver on read.** Violates ADR-0014
(`ResolveProductPrice` writes nothing); a GET request should not insert rows.
