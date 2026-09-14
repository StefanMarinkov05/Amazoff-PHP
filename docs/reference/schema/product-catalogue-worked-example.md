# Product, variation, attributes, images — one worked example

One product, traced through every table that touches it, with the actual rows
each one would hold. No argument here — the reasoning behind the shape is
`explanation/product-variability.md`; this page is the shape itself, made
concrete.

## The product

```
products
  id=1  "Classic Tee"  regular_price=20.00
```

The product is the thing a customer searches for. It owns one image gallery —
this part predates variation galleries and is unchanged by them:

```
product_images  (product_id = 1)
  id=10  red-front.jpg    is_main=true
  id=11  red-back.jpg
  id=12  blue-front.jpg
  id=13  size-chart.jpg        <- belongs to no variation, ever
```

Four rows. All four are the *only* image files that exist for this product,
anywhere. Nothing downstream ever uploads a second copy of one.

## The variations

A variation is one SKU — the thing a customer actually buys, adds to a cart,
and that carries a stock count.

```
product_variations  (product_id = 1)
  id=100  TEE-RED-S
  id=101  TEE-RED-M
  id=102  TEE-BLUE-S
  id=103  TEE-BLUE-M
```

## What a variation *is*: attribute values

`attribute_value_product_variation` — a pivot onto `attribute_values`, which
point at `attributes`. This table answers one question only: **what is this
variation**. It carries no image, no price, nothing about appearance.

```
attributes            attribute_values
  Colour                 Red, Blue
  Size                    S, M

attribute_value_product_variation
  variation=100 (RED-S)   -> Red, S
  variation=101 (RED-M)   -> Red, M
  variation=102 (BLUE-S)  -> Blue, S
  variation=103 (BLUE-M)  -> Blue, M
```

## What a variation *shows*: the image gallery

`product_image_product_variation` — a separate pivot, onto the product's own
`product_images` rows from above. This table answers a different question:
**what does this variation look like**. It carries no colour, no size —
nothing about identity.

```
product_image_product_variation
  image=10 (red-front)   variation=100 (RED-S)   position=1
  image=11 (red-back)    variation=100 (RED-S)   position=2
  image=10 (red-front)   variation=101 (RED-M)   position=1   <- same file, reused
  image=11 (red-back)    variation=101 (RED-M)   position=2
  image=12 (blue-front)  variation=102 (BLUE-S)  position=1
  image=12 (blue-front)  variation=103 (BLUE-M)  position=1   <- same file, reused
```

`size-chart.jpg` (id=13) appears in none of these rows. It stays a
product-level-only image — normal, not orphaned.

## 1 variation, 3 tables

Take `TEE-RED-S` (`product_variations.id = 100`) and ask what each table says
about it:

| Table | What it holds for variation 100 | Answers |
|---|---|---|
| `product_variations` | `id=100, product_id=1, sku=TEE-RED-S` | identity of the row itself, plus price/stock overrides |
| `attribute_value_product_variation` | Red, S | **what it is** |
| `product_image_product_variation` | image 10 @ position 1, image 11 @ position 2 | **what it looks like** |

Three separate join tables, three separate questions, all pointing at the same
`product_variations.id = 100`. They do not overlap by design: identity and
appearance are unrelated tables, which is exactly why Red-S and Red-M can
share the same two photographs — the image pivot has no idea what "Red" or
"S" mean, it only says "this variation shows these files, in this order."

## The one distinction worth holding onto

`product_images` is not independent of variations — it is **shared by** them.
The four rows above are the entire pool of images this product will ever have.
A variation never uploads or owns a file of its own; it only ever *picks from*
that shared pool, in whichever order it wants, through the pivot. One upload,
reused as many times as needed.

## Where each piece is built

| Piece | Table(s) | Written by | Read by |
|---|---|---|---|
| Product images | `product_images` | `AddProductImage`, `SetMainProductImage`, `RemoveProductImage` | `reference/../write-rules/product.md` |
| Variation identity | `attribute_value_product_variation` | plain Eloquent, attached via `AddProductVariation` | `attributeValues()` on `ProductVariation` |
| Variation gallery | `product_image_product_variation` | `SetVariationImages` (the whole ordered set, one call) | `ResolveVariationImage`, `reference/../write-rules/product-variation-images.md` |

`explanation/product-variability.md` has the reasoning behind each of these
shapes — why the differentiator is a pivot and not columns, why images are
shared rather than owned, and what was rejected along the way. ADR-0013 is the
decision record for the gallery specifically.
