# How to document this project

Where a piece of knowledge goes, and what shape it takes when it gets there.

`explanation/documentation-design.md` covers how `docs/` itself is organized —
Diátaxis, the ADR/explanation split, and the writing style. This page covers
the code side and the boundary between the two.

## Two readers

Everything here is read by people and by Claude, and the two fail differently.

A person who cannot find a document asks someone. An agent that cannot find a
document proceeds without it — confidently, and with a plausible answer built
on a wrong premise. That asymmetry drives most of the rules below: knowledge
that is merely *available* is not enough, it has to be reachable from the place
where it is needed.

## Where rationale lives: code or docs

Both can hold a *why*. Putting the same *why* in both is how they drift apart.
One test decides it:

> Would deleting this comment make a future change more likely to be wrong?

Yes, it stays in code. No, it belongs in `docs/`.

Nobody opens `docs/` before editing one line of a `match` block. Rationale that
exists to stop a future edit from silently undoing a decision has to sit where
that edit happens.

| Kind of knowledge | Home |
|---|---|
| Why *this line* looks wrong but isn't | Code comment |
| An external system's quirk that forces the shape of the code | Code comment |
| Why this approach over the alternatives | ADR |
| How several pieces fit together today | `explanation/` |
| A comparative claim — "stricter than X", "unlike Y" | ADR, never code |
| Why something is *absent* | The decision doc, once |

Two of those are worth expanding.

**Comparative claims rot.** A comment in `ArticleStatus` explaining that it is
more permissive than `OrderStatus` becomes false the moment someone edits
`OrderStatus`, and nothing prompts them to look. Claims that span files live in
one place, and for a decision that place is an ADR.

**Explaining an absence usually goes nowhere.** A note on every enum without a
transition matrix, saying the absence is deliberate, teaches readers to skim
past comments. Which cases are excluded, and why, is recorded once — in the ADR
that set the rule.

The mirror of that: a comment that only restates the code goes nowhere either.
Check what it is really doing first. "Grouped by effect on stock rather than by
type" looks like restatement and is not, because it tells the next person which
group a *new* case joins.

## What a comment says

The code says what. A comment earns its place by saying why, why-not, or
watch-out:

```php
// Hashed once per process rather than per user. Bcrypt at the configured cost
// is slow enough that a factory calling Hash::make() per row is a measurable
// share of a test run.
protected static ?string $password = null;
```

```php
// `parent_id` is null rather than ProductCategory::factory(), which is what
// Blueprint generates for a self-referencing key. That version recurses
// forever — every category creates a parent, which creates a parent.
'parent_id' => null,
```

Both answer a question a reader would otherwise have to reconstruct, and both
prevent a specific wrong edit.

## Class and method summaries

A class or enum with a non-obvious role opens with a block saying what part it
plays and what callers must know. Three or four lines, not an essay:

```php
/**
 * Courier-independent shipment status. Econt and Speedy each report their own
 * vocabulary; the raw value is kept alongside in `shipments.raw_status` and
 * mapped onto these cases by the courier gateway.
 */
```

A method block carries the same, plus anything the signature cannot express —
units, ranges, invariants, and the entries that look like mistakes:

```php
/**
 * Statuses reachable from this one. Governed by ADR-0004 — read it before
 * widening or narrowing this table.
 *
 * `New => Confirmed` exists for cash on delivery, which skips the payment leg
 * entirely. Stripe orders go through `AwaitingPayment` first.
 *
 * @return list<self>
 */
```

Methods whose name and signature already say everything get no block. A file
needing a comment every three lines is a file to simplify first.

## Referencing a decision from the code it governs

When an ADR constrains a specific construct, the construct points back at it,
phrased as an instruction rather than a citation:

```php
// Governed by ADR-0004 — read it before widening or narrowing this table.
```

ADR numbers are stable: a superseded ADR keeps its number and gains a
`Superseded by` line, so the pointer never dangles. File paths do not have that
property, which is why the number is what gets written.

Three constraints on these pointers:

- They go on the code that gets edited, not on every file nearby.
- They supplement the local *why*; they never replace it. `see ADR-0004` alone
  forces a context switch just to find out whether it was relevant.
- A load-bearing ADR — one that code comments assume exists — must be
  superseded properly rather than deleted or renumbered.

## Referencing code from docs

Documents name files and sections rather than quoting them. A quoted migration
in an explanation page is a second copy that goes stale silently; a named one
sends the reader to the source.

Specification sections are cited by number (§18, §37) because the numbering is
fixed and greppable across both the spec and the codebase.

## Writing for the machine reader

Three locations, three loading behaviours. Putting content in the wrong one is
why an agent "ignores" a convention:

| Location | When it loads | What belongs there |
|---|---|---|
| `CLAUDE.md` | Every turn, always | Short imperative rules and pointers |
| `.claude/skills/` | On demand, by topic | Detail behind those rules |
| `docs/` | Only when something links to it | Everything else |

`CLAUDE.md` costs context on every request, so it stays short and states rules
rather than arguing them. A convention that exists only in `docs/` and is
linked from nowhere is invisible in practice.

Two rules matter more for the machine reader than the human one:

**Status markers are not optional.** A document describing something unbuilt in
the present tense reads, to an agent, as a description of working code, and it
will build on that premise. `explanation/documentation-design.md` records why
this rule exists here.

**One canonical statement per rule; everything else points at it.** Two copies
of a rule become two different rules, and an agent reading the stale one has no
way to tell. This applies across `CLAUDE.md`, the skills, and `docs/` equally.

## Before opening a PR

- Comments touched by the change are still true. An outdated comment is worse
  than none.
- New non-obvious code carries its *why*, and no comment restates its line.
- A decision made during the change has an ADR, or the change follows one.
- Anything described in the present tense actually exists.
