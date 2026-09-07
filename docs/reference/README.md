# Reference

Just the facts — schema, state diagrams, API shapes, permission tables. No
opinions, no "why" (that's [`../explanation/`](../explanation/) and
[`../adr/`](../adr/)), just what's true right now.

## Top-level facts

- **[specification.md](specification.md)** — the working spec. §37 is the
  graded contract; §1–36 is the wish list §37 draws from; §38 is optional.
  Every `§`-numbered reference anywhere in the docs points here.
- **[coding-conventions.md](coding-conventions.md)** — the single source
  for this project's non-negotiable architecture and security rules.
  `CLAUDE.md` and `src/.ai/guidelines/project-conventions.md` both
  point here rather than restating it — read it before writing or
  reviewing any code.
- **[actions.md](actions.md)** — every Action in `app/Actions`, what it
  writes, who can call it, what it throws.
- **[console-commands.md](console-commands.md)** — every custom Artisan
  command and what invokes it.
- **[permissions.md](permissions.md)** — the role/permission catalogue as
  it exists after `migrate:fresh --seed`.
- **[tech-stack.md](tech-stack.md)** — everything currently installed, no
  rationale (that's `explanation/tech-stack-overview.md` and the ADRs).
- **[local-access.md](local-access.md)** — seeded credentials and the full
  route map for a local Docker run.
- **[demo-showcase-order.md](demo-showcase-order.md)** — the fixed set of
  demo products used for a live walkthrough, and why each was picked.

## [diagrams/](diagrams/)

State, sequence, component, deployment, and object diagrams — PlantUML
source plus rendered SVG/PDF, one folder per diagram. Start at
`whole-project-composite-states/` for a single self-contained picture of
the whole system (both entry gates, the visitor journey, and the full
payment and order state machines inlined), or open the specific piece you
need (`payment-status-states/`, `order-status-states/`,
`stripe-payment-sequence/`, `courier-layers/`, `deployment/`,
`product-variability-objects/`, `storefront-user-states/`). See
[diagrams/README.md](diagrams/README.md) for what each one replaces and
why the others (class, use-case, activity diagrams) were evaluated and
skipped.

## [write-rules/](write-rules/)

The expected-behaviour page per aggregate: refusals, races, what a change
does to state that already exists. One file per write-heavy model —
`product.md`, `cart.md`, `order.md`, `coupon.md`, `auth.md`,
`concurrency.md` (the cross-cutting contested-resource map and lock order),
and the product-variation/category/attribute-value files.

## [schema/](schema/)

Everything about the shape of the data: `schema.md` (the tables),
`../diagrams/entity-relationship/` (the visual form), the two seed-document
fixture-format pages, `product-catalogue-worked-example.md` (one product's
rows table by table), `demo-data.md` (what's actually in the seeded
catalogue), and `open-schema-questions.md` (deferred schema decisions).

## [testing/](testing/)

Every "what did testing prove" document, grouped apart from the system-fact
files above since they change at a different rate and for different
reasons. See [testing/README.md](testing/README.md).
