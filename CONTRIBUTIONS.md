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
| Testing | <ul><li>Factory coverage against real schema constraints</li><li>Action and concurrency coverage</li><li>Mutation testing</li><li>Code coverage tooling (PCOV), benchmarked against Xdebug rather than assumed</li></ul> |
| Validation | Database-level constraints across pivot and catalogue tables |
| Optimization | Database engine, packages, and container tuning — migrations and tests from 8m to 30s |
| Authorization | Permissions, roles, policies, administrator bypass |
| Admin panel | Roles and permissions screen |
| Actions | Inventory and cart business logic, locking for contested state |
| Security | Actor parameter made required, no silent default |
| Documentation | For the above |

### Aleksandar Stanchev

| Area | Work |
|---|---|
| Data model | Initial schema design |
| Admin panel | <ul><li>Resources for the catalogue's lookup entities</li><li>Product resource, with its related entities</li><li>Removed unsafe scaffolded relation actions</li><li>Coupon resource, with reactive form behaviour</li><li>Contact and newsletter resources</li></ul> |
| Seeders | Role and staff account seeding |
| Infra | Local database provisioning on a fresh clone |
| Authorization | Policies for product-related resources |
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
