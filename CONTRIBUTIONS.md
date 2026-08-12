# Contributions

Who built what, and where AI assistance was used. Separate from
`CONTRIBUTING.md`, which is the process guide — this is the record.

## Team

### Both

- Brainstorming — extending the specification, defining a goal and a scope
- Research into the tech stack and which tools to use
- Modelling the data
- Building the project skeleton and setting up a container

### Stefan Marinkov

- Setting up the CI workflow
- `App\Enums` — the twelve backed enums, their casts, and the four status
  transition matrices, drafted with an LLM and reviewed before acceptance
- `tests/Feature/FactoryTest.php` — asserts every factory persists a row
- Database-level validation: composite primary keys on all six pivot tables
  and 45 `CHECK` constraints across 11 tables, so the invariants hold for
  seeders and queued jobs as well as for Form Requests
- Moving the test suite from SQLite in memory to MySQL, without which none of
  those constraints were exercised by a test run
- The authorization layer — 104 permissions, 3 roles, 20 policies, and
  the `Gate::before` administrator bypass, with a test matrix weighted toward
  the denials §37 criterion 18 is graded on
- The roles and permissions admin screen, which is what makes §3.5's
  "editable without a deploy" true rather than architectural
- Documentation for the above

### Aleksandar Stanchev

- Modelled the first prototype of the schema
- Seven Filament resources over the catalogue's lookup entities — `Brand`,
  `Tag`, `ProductCategory`, `ArticleCategory`, `Attribute`, `AttributeValue`,
  `Carrier` — scaffolded and corrected by hand where the generator missed
  unique-index validation and a self-referencing category cycle
- `RoleSeeder`, the `DatabaseSeeder` staff account, and the `User` model
  fixes (`HasName`) needed to make the Filament panel usable end to end
- Diagnosed the local app running against SQLite instead of the project's
  MySQL container, and added `docker/mysql/init/` to auto-provision the
  local test database
- Documentation for the above

## Documentation

Every document in the repository is Stefan's responsibility. Listed for review;
authoring method is covered under AI assistance below.

| Document | What it is |
|---|---|
| `README.md` | Setup and everyday commands |
| `CLAUDE.md` | Architecture and security rules, non-negotiable |
| `CONTRIBUTING.md` | Branching, PR process, review checklist |
| `CONTRIBUTIONS.md` | This file |
| `docs/README.md` | How `docs/` is organized |
| `docs/adr/0001-tech-stack-selection.md` | Kickoff stack decisions and alternatives rejected |
| `docs/adr/0002-db-schema-design.md` | Catalogue schema: attributes, variations, stock ownership |
| `docs/adr/0003-seeding-data.md` | Three seeders, fixture format, validator |
| `docs/adr/0004-state-transitions.md` | Where the order state machine lives |
| `docs/adr/0005-database-level-validation.md` | Which invariants the database enforces, and which cannot be expressed as constraints |
| `docs/adr/0006-authorization-layers.md` | Panel gate, permission, policy, and administrator bypass as four separate mechanisms |
| `docs/explanation/documentation-design.md` | Diátaxis layout, ADR vs explanation |
| `docs/explanation/tech-stack-overview.md` | What is built versus merely installed |
| `docs/explanation/db-schema-design.md` | The parts of the schema the diagram cannot show |
| `docs/explanation/gdpr.md` | Soft versus hard delete, order anonymization |
| `docs/how-to/regenerate-with-blueprint.md` | Safe regeneration and the files Blueprint must not own |
| `docs/how-to/use-ci.md` | What the workflow runs and what a green check does not cover |
| `docs/how-to/run-the-tests.md` | Running one file or one test, and checking that a test can fail |
| `docs/how-to/edit-a-role.md` | Changing permissions through the panel or the seeder, and why they differ |
| `docs/how-to/troubleshooting.md` | Errors that already cost an afternoon, and what stops each recurring |
| `docs/how-to/write-docs-and-comments.md` | Where rationale lives: code or docs |
| `docs/how-to/choose-a-model.md` | Model and effort level per kind of work |
| `docs/how-to/start-a-session.md` | The prompt to paste when starting a Claude Code session, and why each part is in it |
| `docs/reference/specification.md` | The requirements as being built, with deviations marked |
| `docs/reference/schema.md` | Tables, constraints, enum columns |
| `docs/reference/permissions.md` | The 104 permissions, the three roles, and which check answers which question |
| `docs/reference/tech-stack.md` | Versions and packages |
| `docs/reference/erd-diagram.pdf` | Entity relationship diagram |
| `docs/changelog/CHANGELOG.md` | What shipped, and what is still open |

## AI assistance

Used by Stefan from the start of the project. Aleksandar started using it on
2026-08-11, for the Filament admin panel work above. The tool is Claude Code,
configured with skills covering architecture, code review, and documentation
standards so that generated work passes a review pass before it reaches the
repository.

### Scope

Documentation, code comments, summaries, and commit messages — turning draft
notes into full explanatory text. Schema-shaped code where the content is
mechanical: enums, model casts, factories, and scaffolding.

Architectural options are proposed by the LLM and decided by the developer.
Generated output is a first draft and is read before it is trusted; several
decisions in the current codebase were changed during review rather than
accepted as written, including the enum display contracts, which enums carry a
transition matrix, and the split between rationale kept in code and rationale
kept in an ADR.

### Work that gets a stronger model and a closer review

Business logic that carries risk — pricing calculations, stock reservation and
locking, Stripe webhook idempotency, authorization, and the `CourierGateway`
interface. Per the working agreement in `CLAUDE.md`, and the model and effort
levels in `docs/how-to/choose-a-model.md`.

The reasoning is that these are the places where a plausible-looking answer is
most expensive: a wrong permission check and a right one are the same three
lines, and only one of them is discovered by reading. Everything in this
category is verified against the running application rather than accepted from
a green static analysis run — the authorization work above was checked by
logging in as each seeded account, and by deleting a policy to confirm the
test suite went red rather than assuming it would.
