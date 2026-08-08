# Contributions

Who built what, and where AI assistance was used. Separate from
`CONTRIBUTING.md`, which is the process guide — this is the record.

## Team

### Stefan Marinkov

-

### Aleksandar Stanchev

-

## AI assistance

Used by Stefan only, not Aleksandar. For now, scoped to turning draft notes
into full documentation text — not originating content, not making
architectural decisions, not writing business logic.

Business logic that carries risk — pricing calculations, stock
reservation/locking, Stripe webhook idempotency, the order status
transition matrix, Policy classes, the `CourierGateway` interface — is
written by hand, per the working agreement in `CLAUDE.md`, regardless of
what the AI-usage scope is at any given time.
