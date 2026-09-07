# Contributing

Two developers, one repo, graded on architecture and test quality as much as
features. This file is the practical half of that; `CLAUDE.md` is the rules,
`docs/adr/` is why they exist.

## Setup

See `README.md`. Docker Compose, one command, nothing installed on the host
except Docker itself.

## Branching

One branch per feature, named `feat/<name>/<scope>` — e.g.
`feat/stefan/order-status-transitions`. Branch off `main`, rebase on it
daily, open a PR when the slice is done. Don't merge your own PR without the
other developer reviewing it. Don't push directly to `main`.

Keep PRs small enough to review honestly in one sitting. If a change touches
more than one vertical slice, it's two PRs.

## Before opening a PR

```bash
docker compose exec app ./vendor/bin/pint --test
docker compose exec app ./vendor/bin/phpstan analyse --memory-limit=1G
docker compose exec app ./vendor/bin/pest --parallel --processes=4 --testsuite=Feature
docker compose exec app ./vendor/bin/pest --testsuite=Concurrency
```

`--memory-limit=1G` is required, not optional — the container's default 128M
crashes Larastan's parallel workers and reports a fake `Found 1 error`;
`docs/how-to/troubleshooting/ide-and-static-analysis.md` has the full symptom.
`tests/Concurrency`
must never run under `--parallel` — it spawns real subprocesses and needs a
database of its own; `docs/how-to/run-the-tests.md` has the reasoning.

## CI

[`.github/workflows/ci.yml`](.github/workflows/ci.yml) runs Pint, Larastan,
and Pest against a real MySQL service container on every push to `main` and
every pull request, regardless of target branch — Feature and Concurrency
each sharded across parallel jobs rather than run as the two local passes
above. External APIs are mocked, so it needs no real credentials.

A red check does **not** currently block a merge — this repository has no
branch protection rule (private repo, free organisation plan; see
`docs/how-to/troubleshooting/ide-and-static-analysis.md`, "A failing Pint
check reaches `main` anyway"). Run the gate locally before pushing rather
than relying on CI to catch it after the fact; `docs/how-to/use-ci.md` has
the full picture, including where a new test file goes in the shard list.

## Commit messages

`type(scope): short present-tense summary` — Conventional Commits, with a
scope naming the area touched: `feat(payments): add stock reservation to
checkout`, `fix(webhook): signature check bypassed on retry`,
`docs(adr): translation storage decision`, `test(product-review): cover
ApproveProductReview`, `refactor(types): raise Larastan to level 9`,
`style: fix Pint violation`. `style` and a handful of repo-wide changes are
the only cases that go scopeless — everything else names what it touched.
One logical change per commit — not one commit per file, not one commit
for the whole feature.

Written by whoever or whatever is doing the committing at the time, following
the shape above — a human writing their own commit is not the default this
file assumes, only an option to state explicitly when it matters for that
particular commit.

No `Co-Authored-By` trailer, on this repo or any fork/branch of it, even
where AI assistance was used to write the change. `CONTRIBUTIONS.md` is
where that is disclosed — as a record of who built what, not as a per-commit
trailer.

## Changelog

`docs/changelog/CHANGELOG.md` is updated in the same commit as the change it
describes, not afterwards — a changelog written later is written from the diff,
which is the one thing a reader can already see.

What goes in: anything that changes what the application does, what is installed,
or what a developer has to know. Added, Changed, Fixed, Removed, and an `Open`
section for what is known-unresolved. What stays out: refactors with no
behavioural change, formatting, and anything only visible to the person who wrote
it.

Entries are terse and point outward. Reasoning belongs in the ADR the entry
names, not in the entry — one line plus a pointer beats a paragraph that will
disagree with the ADR within a month.

The `Open` section is the part that earns the file. Known-unresolved items live
there so they are not rediscovered as new findings, and they are deleted only
when actually resolved.

**One file, not one per release.** The changelog is read as a timeline —
"what changed between then and now" — and splitting it by release turns one
scroll into a hunt across files. The format is designed for a single file with
newest at the top; the project is not large enough for that to strain.

**Fixed is not a troubleshooting guide.** A `Fixed` entry says a bug is gone. It
does not help the next person who hits the same symptom, because they are
searching for the symptom and the entry is filed under the date of the fix. That
is a different document with a different reader — see below.

## Documentation

`docs/` follows [Diátaxis](https://diataxis.fr/): tutorials, how-to guides,
reference, and explanation, plus `adr/` and `changelog/` for the two things
Diátaxis has no category for. `docs/README.md` states the split in full and
is the place to start if it's unclear which of the four a new doc belongs
in — don't improvise a fifth category or drop a file at the top level of
`docs/` without checking there first.

The shape that matters most in practice: a how-to page is a recipe (steps,
no rationale); an explanation page describes how the system fits together
today (no steps, updated as the system changes); reference is facts with no
opinion (a table, not a paragraph); an ADR argues for one choice at one
moment and is frozen once accepted. Putting *why* in a how-to page, or a
recipe in an explanation page, is the most common way a new doc drifts out
of its own category — if a section starts arguing or start listing numbered
steps, check it's still in the right file.

## Architectural changes

If the change contradicts something in `docs/adr/`, that's an ADR
conversation first, not a silent PR. New decisions get a new ADR (or a new
subsection while the project is still in its init phase — see
`docs/adr/0001-tech-stack-selection.md`), not a comment thread that
disappears once the PR merges.

## What review is checking for

- Business logic in `app/Actions/*`, not in controllers or Livewire
  components.
- Money as `decimal`/`bcmath`, never float.
- Contested state (stock, coupons) locked inside a transaction, not just
  wrapped in one.
- Authorization on every mutating path, not just the ones with a visible
  button.
- Tests for anything on the §37 acceptance list: payment, order, and
  authorization workflows in particular.

`CLAUDE.md` has the full list this is drawn from.
