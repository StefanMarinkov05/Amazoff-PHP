# Explanation

How the system fits together *today*, updated as it changes — not an
argument for a choice (that's [`../adr/`](../adr/)), and not a bare fact
sheet (that's [`../reference/`](../reference/)). Quick test: if the doc is
making a case, it belongs in `adr/`; if it's describing a result, it
belongs here.

- **[system-overview.md](system-overview.md)** — the whole system as one
  state machine, the single orientation diagram to open before the more
  specific pages below.
- **[tech-stack-overview.md](tech-stack-overview.md)** — what's installed
  and why, one level more concrete than the ADR that chose it.
- **[db-schema-design.md](db-schema-design.md)** — the mechanisms the ERD
  and column list can't show: how the catalogue holds its shape, distinct
  from `reference/schema/schema.md`'s bare table-by-table facts.
- **[product-variability.md](product-variability.md)** — how products,
  variations, and attributes relate.
- **[inventory.md](inventory.md)** — stock reservation and how it survives
  concurrent writes.
- **[money.md](money.md)** — the `decimal`/`Money` arithmetic discipline
  and why floats are never used.
- **[concurrency-and-locking.md](concurrency-and-locking.md)** — the
  contested-resource map, what a concurrency test can and cannot prove, and
  the designs that look correct and prove nothing.
- **[couriers.md](couriers.md)** — the Econt/Speedy abstraction, caching,
  and the `__PHP_Incomplete_Class` trap that shape hits.
- **[stripe-payments.md](stripe-payments.md)** — the Stripe integration:
  intents, webhooks, idempotency.
- **[security-model.md](security-model.md)** — the authorization and
  security model this project's security testing checks against.
- **[gdpr.md](gdpr.md)** — personal-data handling and retention.
- **[secrets-and-env.md](secrets-and-env.md)** — the standing policy on
  credentials; read this before an agent goes anywhere near `.env`.
- **[filament-resources.md](filament-resources.md)** — what each file in a
  resource folder does and where Actions plug in, including the
  eager-loading/N+1 rule.
- **[storefront-pages.md](storefront-pages.md)** — the shape a public page
  takes: Livewire component, thin controller, direct Eloquent reads.
- **[demo-seeding.md](demo-seeding.md)** — what the demo dataset contains
  and why it's shaped the way it is.
- **[documentation-design.md](documentation-design.md)** — how this docs
  tree itself is organized and why (Diátaxis, the ADR/changelog split, the
  troubleshooting/security-testing folder splits).
