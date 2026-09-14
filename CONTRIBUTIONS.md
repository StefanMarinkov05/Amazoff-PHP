# Contributions

Who built what, and where AI assistance was used. `CONTRIBUTING.md` is the
process guide; this is the record.

Two people. The work split along the seam between the admin panel and the
layers underneath it. The conflicts came from the two seeders both sides
needed to touch.

## Team

### Together

| Area | Work |
|---|---|
| Specification | Extended the issued spec into a working one, deviations marked |
| Stack | Researched and chose the tools |
| Data model | Modelled the data |
| Project setup | Project skeleton, Docker container |

### Stefan Marinkov

| Area | Work |
|---|---|
| CI | Pipeline setup, parallelization |
| Enums | Backed enums, casts, status transition matrices |
| Testing | <ul><li>Factory coverage against real schema constraints</li><li>Action and concurrency coverage</li><li>Mutation testing; PCOV coverage tooling</li><li>Widget and panel UI tests (responsive, data presentation)</li><li>Adversarial Stripe-webhook suite</li><li>Recurring interactive storefront/admin pass, server-state verified against every claim on the page</li><li>Live 3-D Secure run against the real Stripe test API, end to end through the webhook</li><li>Performance, chaos/failure-injection, and accessibility testing</li></ul> |
| Validation | Database-level constraints across pivot and catalogue tables |
| Optimization | Docker/database tuning (migrations and tests, 8m → 30s); LLM token usage, output quality, and safety mechanisms |
| Authorization | Permissions, roles, policies, administrator bypass; fixed a role's own-permission escalation and a guest basket not surviving login |
| Admin panel | Roles/permissions screen; product image logic and the variation gallery; visual minimalism and statistical widgets; Inventory, Shipment, User, and Payment resources (§37 #15–16, refund surface) |
| Actions | Full `app/Actions`, including the Stripe slice (`CreateStripeIntent`, `HandleStripeWebhookEvent`, `RefundPayment`) |
| Payments | Stripe integration end to end — PaymentIntents, Elements, webhook, refunds; checkout (guest and registered) through server-recalculated totals; verified against the real Stripe API via MCP, including a completed 3-D Secure challenge; fixed the payment form failing to render and a repeat purchase being permanently refused |
| Security | Closed a guest/webhook null-actor authorization bypass; Stripe signing-secret rotation and dispute handling; fixed a header misconfiguration silently blocking card payment; rate-limited every public form that lacked one; closed a secret-in-URL leak on the payment return path |
| Console | `ExpireCarts` (first scheduled command), `RaceWorker` (concurrency harness), `fixtures:validate`/`-articles`, `demo:fetch-images` |
| Seeder | Demo/System/Stress seeder organization; full transactional demo pass; data parser; `CatalogueStressSeeder`/`StressSeeder` for volume testing |
| Storefront | Personal account management panel; category filtering and sorting; public order tracking and order history, both ownership-scoped |
| Documentation | For the above |

Every finding above was closed with a regression test proven able to
fail — the fix reverted, the test confirmed red, then restored — the
standing methodology for this project's own testing discipline, not
repeated per row.

### Aleksandar Stanchev

| Area | Work |
|---|---|
| Data model | Initial schema design |
| Admin panel | <ul><li>Resources for the catalogue's lookup entities</li><li>Product resource, with its related entities</li><li>Removed unsafe scaffolded relation actions</li><li>Coupon resource, with reactive form behaviour</li><li>Contact and newsletter resources</li><li>Product review resource, moderation only</li><li>Order resource, read-only pending the status-transition Action</li><li>Article resource, full CRUD, with a status-change menu generated from the transition matrix rather than hand-written</li></ul> |
| Storefront | <ul><li>Livewire 3 / Tailwind 4 setup and the shared layout, header, and footer</li><li>Catalogue page — search, category and brand facets with live counts, stock and sale filters, sorting, pagination</li><li>Product detail page — variation picker, gallery with thumbnails and arrows, specifications, reviews, breadcrumb, add to basket</li><li>Basket page and header badge (`CartPage`, `CartBadge`) — line quantity editing with server-side refusal handling, coupon apply/remove, discount and VAT display</li><li>About page, contact form, and the footer newsletter signup</li><li>Journal — article index and reading page, generated cover art, editorial type scale</li><li>Design tokens: the `ink`/`marine`/`ember` palette, two radii, the animation set, `prefers-reduced-motion` honoured</li></ul> |
| Pricing | <ul><li>`ResolveProductPrice` and the `ProductPrice` value object, removing a second copy of §11's discount-window rule</li><li>`ResolveCurrentCart`, the storefront's cart lookup</li><li>Found and fixed a VAT-understatement bug in `CalculateCouponDiscount` — a `products`/`categories`-scoped coupon dropped an unmatched line's VAT from the total instead of keeping it at its untouched value, which also understated `CreateOrder`'s recorded `orders.vat_amount` on every order redeeming a scoped coupon against a partially-matched cart</li></ul> |
| Testing | First storefront Livewire test coverage for the cart — `CartPageTest` (17 cases: the ownership gate, quantity-box refusal handling, coupon wiring, and a dedicated regression case for the VAT fix above) and `CartBadgeTest` (6 cases) |
| Seeders | Role and staff account seeding |
| Infra | <ul><li>Local database provisioning on a fresh clone</li><li>Vite/Tailwind asset pipeline under Docker — source scanning, the browser-facing dev origin, font injection</li></ul> |
| Actions | <ul><li>Review approval and unapproval</li><li>Article status transitions (§22), separating `publish` from `update` the way review moderation separates `approve`</li><li>`SubscribeToNewsletter`, where a second writer in the panel is what puts the rule in an Action</li></ul> |
| Authorization | <ul><li>Policies for product-related resources</li><li>Narrowed `content_editor` to the scope §3.3 grants</li></ul> |
| Schema | Fields supporting contact message handling |
| Documentation | For the above, plus `how-to/write-a-storefront-page.md` — the UI patterns reference |

## AI assistance

Stated here rather than left to be inferred from the commit history.

Stefan used AI from the start; Aleksandar from 2026-08-11, for the Filament
panel work. The tool is Claude Code, configured with skills covering
architecture, code review, and documentation standards, so generated work has
been through a review pass before it reaches the repository.

### Where it is used

- Documentation, code comments, summaries, commit messages — draft notes into
  finished text
- Schema-shaped code where the content is mechanical: enums, model casts,
  factories, scaffolding
- Reasoning about concurrency safety, where a test can show a race is
  unlikely but not that it is impossible
- Running tests and analyzing the results
- Performing static analysis and manual testing via MCP Server of a browser

Architectural options come from the developers and the model; the decision is
the developer's. Generated output is a first draft and is read before it is
trusted. Not a formality — several decisions in the codebase were changed
during review rather than accepted as written, including the enum display
contracts, which enums carry a transition matrix, and whether rationale
belongs in the code or an ADR.

### Where it gets a stronger model and a closer read

- Pricing calculations
- Stock reservation and locking
- Stripe webhook signature verification and idempotency
- Authorization
- The `CourierGateway` interface

Per the working agreement in `CLAUDE.md` and the levels in
`docs/how-to/choose-a-model.md`.

These are the places where a plausible-looking answer is most expensive. A
wrong permission check and a right one are the same three lines, and only one
is caught by reading. So this category is verified against the running
application rather than trusted because static analysis came back green — the
authorization work was checked by logging in as each seeded account, and by
deleting a policy to confirm the test suite went red rather than assuming it
would; the webhook idempotency guard the same way, by removing the unique
constraint and the exception catch in turn and confirming each removal broke
a different test.
