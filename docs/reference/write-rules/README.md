# Write rules

The expected-behaviour page per aggregate: refusals, races, and what a
change does to state that already exists. One file per write-heavy model or
concern, cross-referenced from `CLAUDE.md`'s architecture section.

- **[product.md](product.md)** — writes against a product, its variations,
  and their images.
- **[product-attribute-values.md](product-attribute-values.md)** —
  descriptive attribute values ("what is this product").
- **[product-variation-attribute-values.md](product-variation-attribute-values.md)**
  — a variation's own attribute-value combination ("which variation is
  this").
- **[product-variation-images.md](product-variation-images.md)** — a
  variation's own image gallery.
- **[product-category.md](product-category.md)** — category deletion, alone
  and under concurrency.
- **[catalogue-filters.md](catalogue-filters.md)** — what `/catalogue`
  actually filters on and the options available.
- **[cart.md](cart.md)** — writes against a cart and its lines, alone and
  after a guest merge.
- **[coupon.md](coupon.md)** — coupon application, removal, and redemption.
- **[order.md](order.md)** — a cart becoming an order, alone and under
  concurrency.
- **[auth.md](auth.md)** — registration, login, an authenticated session,
  and a password change.
- **[concurrency.md](concurrency.md)** — the cross-cutting map: what is
  contested, what protects it, what proves it. Read this one first if the
  others feel repetitive — it names the shared mechanism each per-aggregate
  page applies.
