# Schema design, the parts the diagram does not show

The column-level facts are in `docs/reference/schema/schema.md`, the ERD alongside it,
and the migrations.
This page covers the mechanisms those cannot convey: how the catalogue holds
product types with nothing in common, and where stock lives. ADR-0002 has the
reasoning behind both; this is a description of the result.

Nothing in `app/` reads any of this yet. The schema, models, factories, and
enum casts exist; the Actions and queries described below are how the tables
are meant to be traversed, not code that currently exists.

## A product is not the thing that gets sold

3 tables sit between a listing and a physical item:

| Table | Holds | One per |
|---|---|---|
| `products` | Name, description, category, brand, base price, VAT rate, SEO | Listing |
| `product_variations` | SKU, optional price override, weight, image, availability | Sellable item |
| `inventories` | Current, reserved, sold, returned, damaged quantities | Sellable item |

`inventories` has `UNIQUE(product_variation_id)`, and neither `products` nor
`product_variations` carries a quantity column. Stock exists in exactly one
place.

The consequence is that **every sellable product has at least 1 variation**,
including products with nothing to vary. A book with a single edition still
gets 1 variation row, because that row is what its stock hangs off. Code that
creates a product without creating a variation produces a listing that can
never be sold.

`product_variations.price` and `discount_price` are nullable, and null means the
product's price applies. A phone whose 512GB variant costs more sets a price on
that variation alone; the other variants inherit, and the description, images,
and SEO fields are not duplicated across them.

`product_variations` soft-deletes. A discontinued variant stays retrievable so
that historical order items still resolve.

## What varies is described, not hard-coded

2 tables define the vocabulary, and two pivots attach it:

| Table | Holds | Example |
|---|---|---|
| `attributes` | An axis a product can vary along | Size, Colour, Storage |
| `attribute_values` | The points on that axis | M, Red, 256GB |
| `attribute_product` | Which axes apply to a product | This shirt varies by size and colour |
| `attribute_value_product_variation` | Which values a variation carries | This variation is M / Red |

A variation has no `size` or `colour` column. It is identified by the *set* of
attribute values attached to it through
`attribute_value_product_variation`.

`attributes.input_type` decides how values render — `select` as a dropdown,
`color` as a swatch drawn from `attribute_values.color_hex`, `text` as free
entry. `attributes.is_filterable` decides whether the axis appears in the
catalogue filter panel at all.

### Worked example

A T-shirt in S/M/L and red/blue:

```
products                         "Cotton T-Shirt"
  attribute_product              → Size, Colour
product_variations               6 rows, one per combination
  TSHIRT-S-RED                   → attribute_values: S, Red
  TSHIRT-S-BLUE                  → attribute_values: S, Blue
  TSHIRT-M-RED                   → ...
  ...
inventories                      6 rows, one per variation
```

A book with no variants:

```
products                         "Dune"
  attribute_product              → (none)
product_variations               1 row
  DUNE-PB                        → attribute_values: (none)
inventories                      1 row
```

The book's variation carries no attribute values at all. It exists so that
`inventories` has something to point at.

## The two pivots answer different questions

`attribute_product` answers *which filters to show*. Reaching the filter panel
for a category means collecting the attributes attached to the products in it.

`attribute_value_product_variation` answers *which products match a filter*.
Finding every product available in red goes from the attribute value, through
the variations carrying it, up to their products.

Reaching a product's available options — the size dropdown on a product page —
also goes through the second pivot: product → variations → attribute values,
not through `attribute_product`, which only records that a size axis exists.

Neither pivot has a unique constraint. Attaching the same attribute to a
product twice, or the same value to a variation twice, currently succeeds.
ADR-0002 records this as a gap needing a follow-up migration.

Nothing at the database level enforces that a variation's set of attribute
values is complete, or that 2 variations of 1 product carry different sets.
Both are invariants for the Action that creates variations to hold.

## Specifications are a different thing that looks the same

`product_specifications` holds free-text name/value pairs attached directly to
a product — warranty length, material, page count, screen size.

| | `attributes` | `product_specifications` |
|---|---|---|
| Shared vocabulary | Yes, referenced by ID | No, free text per product |
| Filterable | Yes | No |
| Forms variations | Yes | No |
| Attached to | Product and variation | Product only |

The distinction is what varies versus what is merely stated. Screen size on a
phone with one screen is a specification. Storage on a phone sold in 128GB and
256GB is an attribute, because it produces 2 variations with two SKUs and two
stock levels.

The same real-world property can be either, depending on the product. Storage
is a specification on a laptop sold in one configuration and an attribute on
the one sold in three.

## Stock is a ledger, not a number

§20 requires stock to change through movements rather than direct writes.
`inventories` holds the current figures; `inventory_movements` is the
append-only record of how they got there, typed by
`App\Enums\InventoryMovementType`.

Available quantity is derived — `current_quantity - reserved_quantity` — and is
not stored.

Because 2 orders can reach the same variation at once, every write to
`inventories` needs `DB::transaction` together with `lockForUpdate()`. The
transaction alone does not prevent the race; it only makes both halves of a
lost update atomic.
