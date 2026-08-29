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
| Testing | <ul><li>Factory coverage against real schema constraints</li><li>Action and concurrency coverage</li><li>Mutation testing</li><li>Code coverage tooling (PCOV)</li></ul> |
| Validation | Database-level constraints across pivot and catalogue tables |
| Optimization | <ul><li>Docker/database tuning — migrations and tests from 8m to 30s</li><li>CI parallelization - halving wall-clock time</li><li>LLM token usage, output quality and safety mechanisms</li></ul> |
| Authorization | Permissions, roles, policies, administrator bypass |
| Admin panel | <ul><li>Roles and permissions screen</li><li>Product image logic, including the variation gallery</li></ul> |
| Actions | Full `app/Actions` |
| Security | Prevented a guest/Stripe-webhook null-actor authorization bypass |
| Console | `ExpireCarts` (the project's first scheduled command), `RaceWorker` (the concurrency test harness), `fixtures:validate`/`fixtures:validate-articles`, `demo:fetch-images` |
| Seeder | <ul><li>Demo/System/Stress seeder organization</li><li>Full transactional demo pass</li><li>Data Parser before seeding</li><li>`CatalogueStressSeeder` and `StressSeeder` for volume testing</li></ul> |
| Documentation | For the above |

### Aleksandar Stanchev

| Area | Work |
|---|---|
| Data model | Initial schema design |
| Admin panel | <ul><li>Resources for the catalogue's lookup entities</li><li>Product resource, with its related entities</li><li>Removed unsafe scaffolded relation actions</li><li>Coupon resource, with reactive form behaviour</li><li>Contact and newsletter resources</li><li>Product review resource, moderation only</li><li>Order resource, read-only pending the status-transition Action</li><li>Article resource, full CRUD, with a status-change menu generated from the transition matrix rather than hand-written</li></ul> |
| Storefront | <ul><li>Livewire 3 / Tailwind 4 setup and the shared layout, header, and footer</li><li>Catalogue page — search, category and brand facets with live counts, stock and sale filters, sorting, pagination</li><li>Product detail page — variation picker, gallery with thumbnails and arrows, specifications, reviews, breadcrumb, add to basket</li><li>About page, contact form, and the footer newsletter signup</li><li>Design tokens: the `ink`/`marine` palette, two radii, two animations, `prefers-reduced-motion` honoured</li></ul> |
| Pricing | <ul><li>`ResolveProductPrice` and the `ProductPrice` value object, removing a second copy of §11's discount-window rule</li><li>`ResolveCurrentCart`, the storefront's cart lookup</li></ul> |
| Seeders | Role and staff account seeding |
| Infra | <ul><li>Local database provisioning on a fresh clone</li><li>Vite/Tailwind asset pipeline under Docker — source scanning, the browser-facing dev origin, font injection</li></ul> |
| Actions | <ul><li>Review approval and unapproval</li><li>Article status transitions (§22), separating `publish` from `update` the way review moderation separates `approve`</li><li>`SubscribeToNewsletter`, where a second writer in the panel is what puts the rule in an Action</li></ul> |
| Authorization | <ul><li>Policies for product-related resources</li><li>Narrowed `content_editor` to the scope §3.3 grants</li></ul> |
| Schema | Fields supporting contact message handling |
| Documentation | For the above |

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

Architectural options come from the developers and the model; the decision is
the developer's. Generated output is a first draft and is read before it is
trusted. Not a formality — several decisions in the codebase were changed
during review rather than accepted as written, including the enum display
contracts, which enums carry a transition matrix, and whether rationale
belongs in the code or an ADR.

### Where it gets a stronger model and a closer read

- Pricing calculations
- Stock reservation and locking
- Stripe webhook idempotency
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
would.
