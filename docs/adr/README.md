# Architecture Decision Records

One decision, one file, frozen the moment it was accepted. A changed mind
gets a new ADR marked `Superseded by ADR-XXXX`, never a rewrite of the old
one — purely additive/subtractive housekeeping (a dead link, a clarifying
note) is the one thing that can still be edited in place.

- **[0001](0001-tech-stack-selection.md)** — Tech stack selection
- **[0002](0002-db-schema-design.md)** — DB schema design
- **[0003](0003-seeding-data.md)** — Seeding data
- **[0004](0004-state-transitions.md)** — State transitions (order status)
- **[0005](0005-database-level-validation.md)** — Database-level validation
- **[0006](0006-authorization-layers.md)** — Authorization layers
- **[0007](0007-action-conventions.md)** — Action conventions
- **[0008](0008-concurrency-control-per-resource.md)** — Concurrency control
  per resource
- **[0009](0009-code-coverage.md)** — Code coverage
- **[0010](0010-ci-parallelization.md)** — CI parallelization
- **[0011](0011-order-status-side-effects.md)** — Order status side effects
- **[0012](0012-laravel-boost.md)** — Laravel Boost
- **[0013](0013-variation-image-ownership.md)** — Variation image ownership
- **[0014](0014-storefront-read-path.md)** — Storefront read path
- **[0015](0015-html-sanitization.md)** — HTML sanitization
- **[0016](0016-payment-intents-over-checkout-sessions.md)** — Payment
  Intents over Checkout Sessions
- **[0017](0017-adopt-pest-browser-testing.md)** — Pest browser tests
  over standalone Playwright
- **[0018](0018-adopt-pest-5.md)** — Upgrade to Pest 5 / PHPUnit 13
- **[0019](0019-gdpr-erasure.md)** — GDPR erasure: anonymise the order,
  delete everything else
