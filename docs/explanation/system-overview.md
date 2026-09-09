# The whole system as one state machine

Every state this project has, inlined into one picture with no cross-file
references: the two entry gates (an IP-blacklist choice for ordinary
visitors and the Stripe webhook's own IP-allowlist choice — both modeled
as the hosting/firewall layer, not application code), the full visitor
journey, the full payment state machine, and the full order state
machine.

This is the single diagram to open for orientation before touching any of
the individual pieces:
[view source](../reference/diagrams/whole-project-composite-states/whole-project-composite-states.puml) ·
[view PDF](../reference/diagrams/whole-project-composite-states/whole-project-composite-states.pdf) —
verified against `App\Enums\OrderStatus`, `App\Enums\PaymentStatus`,
`App\Actions\Order\TransitionOrderStatus`, `routes/web.php`, and
`canAccessPanel()` on 2026-09-07. If this diagram and any of the pieces it
absorbs disagree, the code is right — regenerate.

## The pieces this absorbs, in more detail

Each of these is easier to read in isolation, and each carries its own
verification date and source-of-truth notes:

- **[storefront-user-states](../reference/diagrams/storefront-user-states/)**
  — the visitor's journey in isolation. Discussed in
  `storefront-pages.md`, "The visitor's path through it".
- **[payment-status-states](../reference/diagrams/payment-status-states/)**
  — `PaymentStatus`'s 8 states in isolation. Discussed in
  `stripe-payments.md`.
- **[order-status-states](../reference/diagrams/order-status-states/)** —
  `OrderStatus`'s 11 states in isolation, with the inventory side effect
  on each edge that carries one. Discussed in `reference/write-rules/order.md`.
- **[return-status-states](../reference/diagrams/return-status-states/)** —
  `ReturnStatus`'s 4 states (the 14-day right of withdrawal, ADR-0020).
  Deliberately a *separate* machine that runs beside `orders.status`, not
  inside it — a fully-refunded return leaves the order at `Delivered`.
  Discussed in `reference/write-rules/returns.md`.
- **[security-defense-layers](../reference/diagrams/security-defense-layers/)**
  — not a state machine and not absorbed into the composite diagram above,
  but the other half of "what a request passes through": every layer
  (IP allowlist, host config, validation, rate limiting, DB constraints)
  rather than every state. Discussed in `security-model.md`.

## Why this file exists

Nothing else in `docs/` describes the system state picture as a whole —
`tech-stack-overview.md` covers what's built versus merely installed, and
each of the pieces above lives in the explanation page for its own topic.
This page is the composite's home so the diagram has a real prose
counterpart rather than being reachable only from
`reference/diagrams/README.md`.
