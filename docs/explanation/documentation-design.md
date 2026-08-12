# How the documentation is organized

`docs/` follows [Diátaxis](https://diataxis.fr/), plus two folders it does
not cover.

## Why Diátaxis

The failure mode it prevents is one document trying to do several jobs at
once — a README that starts as a tutorial, turns into a reference table, and
ends with an argument about why the database was chosen. Each of those has a
different reader in a different situation, and merging them serves none of
them.

Diátaxis splits on two axes: whether the reader is *studying* or *working*,
and whether they need *practical steps* or *theory*.

| | Practical | Theoretical |
|---|---|---|
| **Studying** | Tutorials | Explanation |
| **Working** | How-to | Reference |

- **Tutorials** — a newcomer with no goal of their own yet. Learning by
  doing, following a path someone else chose.
- **How-to** — someone with a specific job. They know the system; they need
  the recipe.
- **Reference** — someone mid-task who needs a fact. Schema, columns,
  endpoints. Dry on purpose.
- **Explanation** — someone trying to understand why the system is shaped
  the way it is. Read away from the keyboard.

The rule that keeps it working: **one document does one job.** A how-to that
starts explaining rationale should link to the explanation instead.

## The two additions

**`adr/`** — architecture decision records. Diátaxis has no home for these
because they are not documentation of the system; they are a record of
decisions about it, frozen in time.

**`changelog/`** — what shipped, in order.

## ADR vs explanation

The distinction that is easiest to lose:

An **ADR** argues for one choice at one moment, and is immutable once
accepted. Reversing a decision means a new ADR marking the old one
`Superseded by ADR-XXXX`, never an edit. Its value is partly historical — it
records what the team believed at the time, including when that later turns
out wrong.

**Explanation** describes how the system fits together now. It draws on
several ADRs, has nothing left to defend, and is rewritten whenever the
system changes.

Test: if the document is making a case, it is an ADR. If it is describing a
result, it is explanation.

`explanation/tech-stack-overview.md` and `adr/0001-tech-stack-selection.md`
are the worked example. The ADR argues Filament over hand-built CRUD; the
overview assumes Filament and describes what the admin layer looks like as a
result.

## ADR granularity

One combined ADR (`0001-tech-stack-selection.md`) covers the whole initial
stack rather than one file per package. Everything in it was decided
together at kickoff, before any code existed.

The cost is that superseding one part means superseding a document that
covers many. That cost is accepted for now and revisited once decisions
start being made individually — a schema decision made in week six belongs
in its own ADR, not appended to the kickoff one.

## Status markers

Documents describing something not yet built say so at the top.
`explanation/gdpr.md` opens with a status line because it describes schema
in `misc/draft.yaml` that has not been generated. `tech-stack-overview.md`
separates "Built and running" from "Installed, not wired in".

This exists because the docs drifted once already — an earlier version
described Filament resources, Livewire components, and a `CourierGateway`
in the present tense when none of them existed. Documentation that describes
intentions in the present tense is worse than no documentation, because it
cannot be distinguished from documentation that is merely out of date.

## Writing style

Plain and declarative. No rhetorical build-up, no persuasive framing, no
instructions addressed to the reader ("you should decide X"). Documents
state what is true and what is undecided; they do not assign work.

## What is not in `docs/`

`CLAUDE.md` (architecture rules), `CONTRIBUTING.md` (process),
`CONTRIBUTIONS.md` (who did what), and `README.md` (setup) live at the repo
root, where both people and tooling expect them.

`misc/` is gitignored and holds planning material — earlier drafts, the
`draft.yaml` schema and its review. Nothing in `docs/` should depend on
`misc/` being present.
