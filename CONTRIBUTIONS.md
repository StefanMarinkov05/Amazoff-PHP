# Contributions

Who built what, and where AI assistance was used. `CONTRIBUTING.md` is the
process guide; this is the record.

Two people. The work split along the seam between the admin panel and the
layers underneath it. The conflicts came from the two seeders both sides
needed to touch.

## Team

### Together

- Extended the issued specification into a working one, with deviations marked
- Researched the stack and chose the tools
- Modelled the data
- Built the project skeleton and the Docker container

### Stefan Marinkov

- CI workflow
- `App\Enums` — twelve backed enums, their casts, and four status transition
  matrices. Drafted with an LLM, reviewed before acceptance
- `tests/Feature/FactoryTest.php` — persists one row from every factory. The
  only check in the suite that catches a factory writing a value its column
  cannot hold
- Database-level validation — composite primary keys on all six pivot tables,
  45 `CHECK` constraints across 11 tables. Holds for seeders and queued jobs,
  not only Form Requests
- Moved the test suite from SQLite in memory to MySQL. SQLite ignores most of
  those constraints, so the suite had been green while guaranteeing nothing
- Authorization — 104 permissions, three roles, twenty policies, and the
  `Gate::before` administrator bypass. Test matrix weighted toward the
  denials, which is what §37 criterion 18 is graded on
- Roles and permissions admin screen. Without it §3.5's "editable without a
  deploy" was a claim nobody could exercise
- Documentation for the above

### Aleksandar Stanchev

- First prototype of the schema, which the Blueprint draft was built from
- Seven Filament resources over the catalogue's lookup entities — `Brand`,
  `Tag`, `ProductCategory`, `ArticleCategory`, `Attribute`, `AttributeValue`,
  `Carrier`. Scaffolded, then corrected by hand: the generator does not infer
  unique-index validation from the schema and produced a self-referencing
  category cycle
- `RoleSeeder`, the `DatabaseSeeder` staff account, and the `User` model fixes
  (`HasName`). The panel crashed on every authenticated page without them
- Diagnosed the local app running against SQLite rather than the project's
  MySQL container, and added `docker/mysql/init/` so the test database
  provisions itself on a fresh clone
- Documentation for the above

## Documentation

Every document in the repository is Stefan's responsibility. They are listed
here so a reviewer can find them without walking the tree; how they were
written is covered under AI assistance below.

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
