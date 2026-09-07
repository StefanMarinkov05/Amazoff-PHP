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

## Two readers

Everything here is read by people and by Claude, and the two fail
differently. A person who cannot find a document asks someone. An agent
that cannot find a document proceeds without it — confidently, and with a
plausible answer built on a wrong premise. That asymmetry is why knowledge
that is merely *available* is not enough: it has to be reachable from the
place where it is needed, which is what drives the router structure in
`CLAUDE.md` and the loading-behaviour table in `how-to/write-docs-and-comments.md`.

It also means folder membership matters less here than it would for a
documentation site a human browses top to bottom. An agent reaches a
document by a named path from `CLAUDE.md`, not by wandering `docs/`, so a
document filed under a slightly wrong quadrant costs little as long as
something links to it — see "Why there is no `tutorials/`" below for the
sharpest case of this: a document shaped for sequential human reading is
actively worse for an agent, not merely miscategorized.

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

## Why there is no `tutorials/`

Diátaxis names four quadrants, but does not require all four to be filled.
Its own guidance on adopting the framework is explicit: "it certainly does
not mean that you should create empty structures for tutorials/howto
guides/reference/explanation with nothing in them. Don't do that. It's
horrible." An empty quadrant is a legitimate state, not an oversight —
`docs/README.md` linked to a `tutorials/` folder that was never created,
which was exactly that anti-pattern, and the link has been removed rather
than filled.

A tutorial, in Diátaxis's own definition, is a guided, sequential
experience — a newcomer follows a chosen path, in order, and builds
confidence by producing a working result along the way. That definition
assumes a reader who arrives once, reads start to end, and has no goal of
their own yet. Neither reader this project actually has fits that:

- **There is no second human developer.** This is a small internship team;
  everyone on it already built the system they'd be tutorialized through.
  A tutorial written for a reader who doesn't exist goes stale silently,
  because nobody ever runs it end to end to notice.
- **An AI agent is not a sequential reader.** An agent retrieves the
  chunk that matches its current task; it does not follow a guided path
  in order and gains nothing from the narrative scaffolding a tutorial is
  built from. Feeding tutorial-shaped prose to an agent (including as a
  retrieval source) produces the worst kind of context for it — heavy on
  transitional narrative, light on the facts-per-token that `reference/`
  and `explanation/` are already optimized to deliver. What an agent needs
  on arrival is orientation, not guided practice, and that need is served
  by `CLAUDE.md` and `how-to/start-a-session.md` — which point at what to
  read and in what order, without pretending to be a learning exercise.

None of this is a case against tutorials in general — it's that this
project's two actual readers both fail the "guided newcomer" precondition
a tutorial is written for.

**Revisit this if**: a second human developer joins the team. That's the
condition that would introduce a reader tutorials are actually for.

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

Numbers as digits, not words — `15 Resources`, not `fifteen Resources`. A
count is a fact a reader (or an agent) scans for, and a spelled-out number
does not match a `grep` for the digit the way the actual count in the code
does. Applies to code comments too, not just `docs/`.

Not every spelled-out number is a count, though — "one" and "two" are
articles as often as they are quantities: "one table, no invariant" states a
fact about cardinality; "the one Action that opens no transaction" means "the
single Action," and digitizing it reads as a typo. A mechanical find-and-
replace on a noun that follows a number cannot tell the two apart — verified
the hard way applying this rule project-wide, which corrupted roughly twenty
sentences from "one Action owns X" into "1 Action owns X" and needed a second
pass, by eye, to fix. Read the sentence; digitize only where a reader would
naturally write the numeral.

## What is not in `docs/`

`CLAUDE.md` (architecture rules), `CONTRIBUTING.md` (process),
`CONTRIBUTIONS.md` (who did what), and `README.md` (setup) live at the repo
root, where both people and tooling expect them.

`misc/` is gitignored and holds planning material — earlier drafts, the
`draft.yaml` schema and its review. Nothing in `docs/` should depend on
`misc/` being present.
