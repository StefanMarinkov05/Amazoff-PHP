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
