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
| CI | Pipeline setup |
| Enums | Backed enums, casts, status transition matrices |
| Testing | <ul><li>Factory coverage against real schema constraints</li><li>Action and concurrency coverage</li><li>Mutation testing</li><li>Code coverage tooling (PCOV)</li><li>Widget and panel UI tests (responsive, data presentation etc.)</li><li>An adversarial suite against the Stripe webhook, validated by swapping in a deliberately vulnerable signature check and confirming it failed</li><li>A recurring interactive pass over the storefront and admin panel — real browser sessions, real accounts per role, server-state checked against every claim on the page</li><li>A live 3-D Secure payment run against the real Stripe test API, end to end through the webhook</li><li>Performance testing</li><li>Chaos/failure-injection testing</li><li>Accessibility testing</li><li>Every finding closed with a regression test proven able to fail — the fix reverted, the test confirmed red, then restored</li></ul> |
| Validation | Database-level constraints across pivot and catalogue tables |
| Optimization | <ul><li>Docker/database tuning — migrations and tests from 8m to 30s</li><li>CI parallelization — halving wall-clock time</li><li>LLM token usage, output quality and safety mechanisms</li></ul> |
| Authorization | Permissions, roles, policies, administrator bypass, plus fixes to a role's ability to escalate its own permissions and to a customer's guest basket surviving login |
| Admin panel | <ul><li>Roles and permissions screen</li><li>Product image logic, including the variation gallery</li><li>Visual minimalism and statistical widgets</li><li>Inventory, Shipment, User, and Payment resources — closing §37 criteria 15 and 16, and the admin surface for refunds</li></ul> |
| Actions | Full `app/Actions`, including the Stripe slice — `CreateStripeIntent`, `HandleStripeWebhookEvent`, `RefundPayment` |
| Payments | <ul><li>Stripe integration end to end — PaymentIntents, Elements, webhook, refunds</li><li>Checkout — guest and registered, cart through payment to order confirmation, server-recalculated totals</li><li>Verified against the real Stripe API via its MCP server, alongside the faked test suite, including a completed 3-D Secure challenge</li><li>Fixed the payment form failing to render and a repeat purchase in one session being permanently refused</li></ul> |
| Security | <ul><li>Prevented a guest/Stripe-webhook null-actor authorization bypass</li><li>Signing-secret rotation and dispute handling for the Stripe webhook</li><li>Closed a header misconfiguration that silently blocked card payment in every environment</li><li>Rate-limited every public form that lacked one</li><li>Closed a secret-in-URL leak on the payment return path</li></ul> |
| Console | `ExpireCarts` (the project's first scheduled command), `RaceWorker` (the concurrency test harness), `fixtures:validate`/`fixtures:validate-articles`, `demo:fetch-images` |
| Seeder | <ul><li>Demo/System/Stress seeder organization</li><li>Full transactional demo pass</li><li>Data Parser before seeding</li><li>`CatalogueStressSeeder` and `StressSeeder` for volume testing</li></ul> |
| Storefront | <ul><li>Personal account management panel</li><li>Category filtering, sorting criteria</li><li>Public order tracking and a customer's own order history, both scoped by ownership rather than by a guessable identifier</li></ul> |
| Documentation | For the above |

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
