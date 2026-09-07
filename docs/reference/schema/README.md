# Schema

Everything about the shape of the data — tables, the visual diagram, the two
seed-document formats, and what's actually in the demo dataset.

- **[schema.md](schema.md)** — the tables. Generated from
  `src/draft.yaml` by Blueprint; that file is the source of truth,
  this page is the readable form.
- **[../diagrams/entity-relationship/](../diagrams/entity-relationship/)**
  — the visual entity-relationship diagram: every table, every column,
  every foreign key, generated from the live schema.
  **[erd-diagram.pdf](erd-diagram.pdf)** is the earlier Blueprint-generated
  version, kept as historical reference but no longer maintained.
- **[fixture-format.md](fixture-format.md)** — the JSON shape
  `FixtureLoader` consumes and `fixtures:validate` checks, for demo product
  data.
- **[article-fixture-format.md](article-fixture-format.md)** — the JSON
  shape `ArticleFixtureLoader` consumes and `fixtures:validate-articles`
  checks, for demo blog/news content.
- **[product-catalogue-worked-example.md](product-catalogue-worked-example.md)**
  — one product, traced through every table that touches it, with the
  actual rows — for when the product/variation/attribute/image
  relationships need to be seen rather than reasoned about.
- **[demo-data.md](demo-data.md)** — what's actually in the seeded
  catalogue: exact SKUs for every notable state, for a live demo or
  presentation.
- **[open-schema-questions.md](open-schema-questions.md)** — schema
  decisions deferred rather than made, each with what would trigger
  revisiting it.
