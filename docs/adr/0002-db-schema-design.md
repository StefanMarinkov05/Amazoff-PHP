# ADR-0002: Product catalogue schema

Status: Accepted
Date: 2026-08-10 · Deciders: team

## Context

§27 lists around 25 entities. The catalogue has to hold product types with
almost nothing in common: a phone varies by storage, colour, and operating
system; a shirt by size and colour; a book by cover type, and carries an ISBN
and a page count that no other category has.

§7 requires each variation to have its own SKU, price, stock, and image. §8
requires filtering by attribute across the whole catalogue.

The specification also assigns stock three different owners: §6 puts an
available quantity on the product, §7 puts one on the variation, and §20
describes an inventory table with movements. Implementing all three would give
three numbers that disagree the first time one of them is written without the
others.

## Decision

### Fixed value sets are backed enums

Every column with a fixed set of allowed values is a MySQL `enum` column with a
matching backed enum in `App\Enums` — twelve enums covering all 19 such
columns. The enum is the source of truth in application code: models cast to
it, factories draw from `cases()`, and the fixture validator checks membership
against it rather than restating the list.

Display behaviour hangs off the enum through Filament's `HasLabel` and
`HasColor` contracts rather than plain `label()` and `color()` methods, because
Filament reads the contracts automatically — a badge column renders the right
text and colour with no configuration. Methods under any other name would have
to be wired up in every resource that shows the column, which is the same list
restated once per screen.

Which statuses may follow which is a separate question with a separate answer;
see ADR-0004.

### Variability through attributes and values, not columns

Four tables carry it:

- `attributes` — the axis a product can vary along: Size, Colour, Storage.
  Carries `input_type` for how its values render and `is_filterable` for
  whether it appears in the catalogue filter panel.
- `attribute_values` — the points on that axis: M, Red, 256GB. `color_hex`
  holds a swatch for `input_type = color`.
- `attribute_product` — which axes apply to a product. This is the declaration
  that a shirt varies by size and colour at all.
- `attribute_value_product_variation` — which specific values a given variation
  carries. A variation is identified by its *set* of attribute values rather
  than by any column of its own.

Adding a product type does not change the schema. A book gets a Cover attribute
with Hard and Soft values; nothing else moves.

### Stock lives only on inventories, keyed to the variation

`inventories` has `UNIQUE(product_variation_id)`. Neither `products` nor
`product_variations` carries a quantity column. This resolves the §6/§7/§20
conflict in favour of §20: one owner, one number.

It follows that every sellable product has at least one variation, including
products with nothing to vary. A book with a single edition still gets one
variation row, because that row is where its stock lives.

### Variation price is nullable and inherits

`product_variations.price` and `discount_price` are nullable; null means the
product's price applies. A phone whose 512GB variant costs more sets a price on
that one variation and leaves the rest untouched, without duplicating the
description or the SEO fields across variants.

### Datetime columns are cast `datetime`, never Blueprint's `timestamp`

Blueprint's generated model casts use `'timestamp'` for every `timestamp()`
column. That cast returns a Unix integer, not a `Carbon` instance — so any
comparison against one (`->lt()`, `->gt()`, a discount or coupon window check)
is a `TypeError`, not a wrong answer. The failure is silent until the first
comparison runs, which for a discount window is the first time a product
carrying one reaches the cart.

Found on `Product::discount_starts_at`/`discount_ends_at` while writing the
cart-Actions tests (`ResolveVariationPrice` needs exactly this comparison),
and then on ten more columns across `Article`, `Cart`, `ContactMessage`,
`Coupon`, `NewsletterSubscriber`, `Order`, `Payment`, `PaymentEvent`,
`Shipment`, and `ShipmentTrackingEvent` — Blueprint generated the same mistake
everywhere it generated a datetime cast at all. Fixed in every model in one
pass; `'datetime'` is what every one of them needed.

Applies wherever a model casts a `timestamp()` column, not only to the
catalogue tables this ADR otherwise covers — recorded here because this is
where a caster reads before adding the next one.

### Specifications are not attributes

`product_specifications` holds free-text name/value pairs — warranty length,
material, page count. They display on the product page and nothing else: they
do not vary, are not filterable, and do not form variations. Attributes are the
opposite on all three counts. The two are separate tables because collapsing
them would mean every display-only fact entering the filter panel.

## Consequences

+ New product categories need no migration. The catalogue holds electronics,
  clothing, and books through the same four tables.
+ Filtering is uniform: one query shape covers every attribute rather than one
  per category.
+ Stock has exactly one owner, so no reconciliation between two quantity
  columns is possible.

− Neither pivot table has a unique constraint or a primary key. The same
  attribute can be attached to a product twice, and the same attribute value to
  a variation twice. Migrations are append-only, so this needs a new migration
  adding `UNIQUE(attribute_id, product_id)` and
  `UNIQUE(attribute_value_id, product_variation_id)`.
− Nothing at the database level guarantees that a variation's set of attribute
  values is complete, or that two variations of one product do not carry
  identical sets. Both are application invariants and need enforcing in the
  Action that creates variations.
− Filtering traverses two pivots. Index choice on both matters and has not been
  measured against realistic row counts yet.
− A single-variation product carries a row whose only purpose is to hold stock.
  This is the price of one stock owner rather than two.
− The schema exists as migrations, models, and factories only. No application
  code reads it yet, so none of the above is proven against real queries.

## Alternatives rejected

- **A column per property on `products`.** `size`, `color`, `storage`,
  `cover_type`, `isbn`. Every new category is a migration, and every row carries
  the columns of every category it does not belong to.
- **A JSON column of attributes on `products`.** No referential integrity, no
  shared vocabulary between products, and filtering degrades to JSON path
  queries that indexes serve poorly.
- **A table per product category.** Products, ClothingProducts, BookProducts.
  Multiplies every query, every policy, and every admin resource by the number
  of categories.
- **Stock on `products`.** Cannot express a shirt that is out of stock in M and
  available in L, which §7 requires.
- **Stock on both `products` and `product_variations`.** A denormalized total
  alongside per-variation quantities. Faster catalogue reads, at the cost of
  two numbers that drift apart under concurrent orders — the exact class of bug
  `lockForUpdate()` exists to prevent.
- **Variations sharing the product's SKU.** §7 requires a per-variation SKU;
  warehouse and courier documents identify the physical item, not the listing.
