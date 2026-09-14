# Diagrams

Source plus rendered output, side by side, so a reader can view the picture
without a PlantUML toolchain and an editor can find the source to update it.
One folder per diagram: `<name>/<name>.puml` (source, edit this) plus
`<name>.svg` and `<name>.pdf` (rendered, regenerate after editing the source
— never hand-edit the rendered files).

## Editor preview (recommended)

`.vscode/extensions.json` recommends the `jebbs.plantuml` VS Code
extension — opening this repo prompts to install it. Once installed, open
any `.puml` file and preview it inline (Alt+D by default), or export
straight from the editor. It renders against the public plantuml.com
server by default, so no local Java/Graphviz is needed. This is why the
prose links elsewhere in `docs/` point at the `.puml` source rather than
the SVG: with the extension installed, clicking the source link previews
the current diagram directly.

## Regenerating after an edit

Without the extension, or to update the committed SVG/PDF after an edit:

```bash
docker run --rm -v "$(pwd)/docs/reference/diagrams/<name>":/data plantuml/plantuml -tsvg /data/<name>.puml
docker run --rm -v "$(pwd)/docs/reference/diagrams/<name>":/data plantuml/plantuml -tpdf /data/<name>.puml
```

Run from the repo root. Both formats are committed: SVG for viewing inline
on GitHub/in an editor without the extension, PDF for printing or sharing
outside a Git host.

## What's here

Chosen against a real research pass over the UML diagram catalogue (Tier 1
built first, Tier 2 followed) — see the session that produced these for the
full reasoning on which diagram types earn their place in this project and
which don't (class, use-case, activity, communication, component, package
diagrams were all evaluated and skipped as redundant with existing prose or
tables). Every diagram below carries its own verification date and source
files in its `.puml` header comment — trust that over this list if they
disagree.

- **[whole-project-composite-states/](whole-project-composite-states/)**
  — everything below, in one self-contained diagram: the two entry gates
  (an IP-blacklist choice and the Stripe webhook's own IP-allowlist choice
  — both modeled as the hosting/firewall layer, not application code),
  the full visitor journey, the full payment state machine, and the full
  order state machine, all inlined with no cross-file references. The
  single diagram to open for orientation; the others below are its pieces
  in more detail and easier to read in isolation.
- **[storefront-user-states/](storefront-user-states/)** — the site's
  logical state map: anonymous browsing, auth, shopping, checkout (guest
  and signed-in converge at order confirmation), account, order tracking,
  and the admin-panel gate, each state annotated with its actual routes.
  Verified against `routes/web.php` and `canAccessPanel()`.
- **[payment-status-states/](payment-status-states/)** — `PaymentStatus`'s
  8 states in full, including the parts a table hides: the `Failed` retry
  loop back into the live region, the `PartiallyRefunded` self-loop, and
  the `Disputed` win/lose fork. Source of truth:
  `App\Enums\PaymentStatus::allowedTransitions()`.
- **[order-status-states/](order-status-states/)** — `OrderStatus`'s 11
  states with the inventory side effect bolded on each edge that carries
  one (`ReleaseStock`/`CompleteSale`/`RestockReturn`) — `TransitionOrderStatus`
  is the only writer of `orders.status`. Source of truth:
  `App\Enums\OrderStatus::allowedTransitions()` and
  `TransitionOrderStatus::applyInventoryEffect()`.
- **[stripe-payment-sequence/](stripe-payment-sequence/)** — the checkout →
  Stripe → webhook message flow, replacing the hand-drawn ASCII diagram
  that used to live in `explanation/stripe-payments.md`. Shows the
  asymmetry between the browser's redirect and the webhook's async write
  to `payments.status`, plus the cash-on-delivery alt path.
- **[stock-reservation-race/](stock-reservation-race/)** — the two-request
  interleaving that oversells stock without a lock, replacing the `t1..t10`
  ASCII timeline in `explanation/concurrency-and-locking.md`. The
  overlapping activation bars make the race visible in a way a text table
  of timestamps only implies.
- **[courier-layers/](courier-layers/)** — the four-layer courier stack
  (Facade → Manager → CachedCourierGateway decorator → CourierGateway
  contract → Econt/Speedy Saloon connectors), replacing the ASCII
  box-stack in `explanation/couriers.md`. Component diagram, not class —
  the pattern names (facade/decorator/strategy) are the point.
- **[deployment/](deployment/)** — local Docker Compose, the actual Railway
  beta (ADR-0023 platform, ADR-0024 Railpack build), and production Forge
  (ADR-0001, never provisioned), side by side. The gap between Local and
  Forge is why `how-to/deploy-and-host.md` exists as a checklist at all;
  Railway has no equivalent gap, since Railpack builds its own image and
  never reads this repo's `docker/nginx/*.conf`.
- **[product-variability-objects/](product-variability-objects/)** — one
  real product ("Classic Tee", the same worked example as
  `../schema/product-catalogue-worked-example.md`) as instances, showing
  what a class diagram of this schema can't: two separate pivots
  (`attribute_value_product_variation` for identity,
  `product_image_product_variation` for appearance) sharing one
  `attribute_values` vocabulary table, and a price that's computed at
  read time rather than stored.
- **[entity-relationship/](entity-relationship/)** — every domain table,
  every column, and every foreign key, grouped by the same areas
  `../schema/schema.md` uses, with a legend (marker and connector meaning)
  as the first thing the diagram shows. Supersedes `../schema/erd-diagram.pdf`
  (a Blueprint-generated PDF, kept as historical reference but no longer
  the maintained diagram) now that the schema work this was waiting on
  (2026-09-07) is done. Crow's-foot, not Chen — matches this folder's
  PlantUML toolchain rather than introducing a second one; generated
  mechanically from `information_schema` rather than hand-typed, so it can
  be regenerated the same way after the next schema change.
  **[entity-relationship/schemaspy/](entity-relationship/schemaspy/)** is a
  second, independently tool-generated ERD (SchemaSpy, straight off
  `information_schema`) kept alongside as a cross-check — it includes
  Laravel's framework tables and live row counts the hand-authored version
  deliberately omits, and confirming the two agree on every relationship
  is the actual value of having both rather than trusting one.
- **[module-coupling/](module-coupling/)** — the ten `app/Actions/{Area}/`
  modules (plus `App\Support` as an eleventh, shared one) and every real
  cross-module Action-to-Action `use` statement, not the entity-relationship
  diagram's foreign keys redrawn with a package boundary around them — a
  foreign key is not the same coupling a PHP `use` statement is, and this
  project's "no repository pattern" rule means a read crossing an area
  boundary through Eloquent is deliberate, not a violation this diagram
  should flag. 6 cross-module edges among 10 modules, all one-directional,
  no cycles — the diagram this project's low-coupling claim can actually
  point at.
- **[security-defense-layers/](security-defense-layers/)** — every layer a
  request or a piece of data actually passes through, in the real order:
  IP allowlist → host configuration (cookies, headers, protocol, CSP,
  encryption) → client-side validation → **Livewire property hydration**
  (SEC-014 through SEC-017 — a strict-typed property throws before any
  handler runs, a client-settable string with no `->maxLength()` reaches a
  write raw, an array cast with no `is_numeric()` guard silently fabricates
  a value) → server-side validation → rate limiting → DB-level validation.
  Not every box applies to every route — the diagram says which ones do.
  Verified against `bootstrap/app.php`, `VerifyStripeWebhookSignature`,
  `EnsureAccountIsActive`, `SetSecurityHeaders`, `ThrottlesSubmissions`,
  CLAUDE.md's "Security rules that are ours", ADR-0005, and
  `docs/reference/testing/security-testing/sec-014.md` through
  `sec-017.md`.

## Not yet built

- `ShipmentStatus` / `ArticleStatus` transition diagrams — each is a real
  state machine with `canTransitionTo()` logic today, and each currently
  exists only as prose/code, not a picture. Worth building if a reader
  needs a fifth logical-state-adjacent diagram; not built because nobody
  asked for it yet.
- Use case, class, activity, communication, component (beyond
  `courier-layers`), and package diagrams for the rest of the codebase —
  evaluated and skipped. §37's numbered criteria already do use-case's job
  more precisely; the ERD does class's job better for an Eloquent app;
  the write-rules pages already state business rules a component diagram
  can't express (e.g. "never assign `->status` directly").
