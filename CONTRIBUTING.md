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
docker compose exec app ./vendor/bin/phpstan analyse
docker compose exec app ./vendor/bin/pest
```

All three run in CI too (`.github/workflows/ci.yml`), against a real MySQL
service container. A red check blocks the merge once branch protection is
turned on — don't rely on catching failures after the fact.

## Commit messages

Type, then a short present-tense summary: `feat: add stock reservation to
checkout`, `fix: webhook signature check bypassed on retry`, `docs: adr for
translation storage`. One logical change per commit — not one commit per
file, not one commit for the whole feature.

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
