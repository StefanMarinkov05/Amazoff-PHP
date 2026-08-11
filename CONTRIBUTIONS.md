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
- All project documentation to date, listed below

### Aleksandar Stanchev

- Modelled the first prototype of the schema

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
| `docs/explanation/documentation-design.md` | Diátaxis layout, ADR vs explanation |
| `docs/explanation/tech-stack-overview.md` | What is built versus merely installed |
| `docs/explanation/db-schema-design.md` | The parts of the schema the diagram cannot show |
| `docs/explanation/gdpr.md` | Soft versus hard delete, order anonymization |
| `docs/how-to/regenerate-with-blueprint.md` | Safe regeneration and the files Blueprint must not own |
| `docs/how-to/use-ci.md` | What the workflow runs and what a green check does not cover |
| `docs/how-to/troubleshooting.md` | Errors that already cost an afternoon, and what stops each recurring |
| `docs/how-to/write-docs-and-comments.md` | Where rationale lives: code or docs |
| `docs/how-to/choose-a-model.md` | Model and effort level per kind of work |
| `docs/reference/specification.md` | The requirements as being built, with deviations marked |
| `docs/reference/schema.md` | Tables, constraints, enum columns |
| `docs/reference/tech-stack.md` | Versions and packages |
| `docs/reference/erd-diagram.pdf` | Entity relationship diagram |
| `docs/changelog/CHANGELOG.md` | What shipped, and what is still open |

## AI assistance

Used by Stefan only, not Aleksandar. The tool is Claude Code, configured with
skills covering architecture, code review, and documentation standards so that
generated work passes a review pass before it reaches the repository.

### Scope

Documentation, code comments, summaries and commit messages — turning draft notes into full
explanatory text. Schema-shaped code where the content is mechanical: enums,
model casts, factories, and scaffolding.

Architectural options are proposed by the LLM and decided by the developer.
Generated output is a first draft and is read before it is trusted; several
decisions in the current codebase were changed during review rather than
accepted as written, including the enum display contracts, which enums carry a
transition matrix, and the split between rationale kept in code and rationale
kept in an ADR.

### Written by hand or Deep reasoning model and hardly reviewed regardless of scope

Business logic that carries risk — pricing calculations, stock reservation and
locking, Stripe webhook idempotency, Policy classes, and the `CourierGateway`
interface. Per the working agreement in `CLAUDE.md`.
