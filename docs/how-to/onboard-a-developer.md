# How to onboard a new developer

A reading and setup path for someone joining this project, so their first
day produces a running app and their first week produces one real change —
not a survey of the whole codebase before touching anything.

This is a how-to, not a tutorial: it is a specific job (get one named
person running and oriented) with a fixed set of steps, not a guided
exercise for building transferable skill. `explanation/documentation-design.md`
covers why this project's tutorial quadrant stays empty and what plays
that role instead — this doc is part of the answer.

## Day 1: get it running

1. **Read `README.md`, then run its Setup section.** Don't skip to
   `docker compose up` from memory of another Laravel project — this one's
   quirks (the app lives in `src/`, not repo root; `filament:assets`
   needs running explicitly) are exactly the kind of thing that costs an
   hour if skipped.
2. **Seed real demo data**, not just the schema:
   ```bash
   docker compose exec app php artisan demo:seed --fresh
   ```
   `docs/reference/schema/demo-data.md` is the full inventory — skim it
   once so you know it exists, don't try to memorize it.
3. **Log in as `admin@example.com`** (see `reference/local-access.md` for
   the password) and click through `/admin`. Then log in as
   `customer@example.com` and click through the storefront. Seeing the app
   from both sides before reading a line of code makes the architecture
   docs land — they keep referring to "the storefront" and "the panel" as
   two different things with different rules.

## Day 1, still: the four documents that matter before writing code

Read in this order, all four, before your first PR — not because the rest
of `docs/` doesn't matter, but because these four are the ones a wrong
assumption from skipping actually costs you:

1. **`CLAUDE.md`** (repo root) — the router and the non-negotiable rules:
   commit discipline, Docker-only commands, migrations are append-only.
2. **`docs/reference/coding-conventions.md`** — the actual architecture
   rules: Actions own business logic, no repository pattern, money is
   `decimal`/`Money` never float, `TransitionOrderStatus` is the only
   writer of `orders.status`. Every rule here is load-bearing, not a
   suggestion — if something you're asked to build seems to want to break
   one, that's a signal to ask, not to route around it quietly.
3. **`docs/README.md`** — how the rest of `docs/` is organized, so you
   know where to look instead of guessing or re-deriving an answer that's
   already written down.
4. **`docs/how-to/troubleshooting/`** (the index page, not every entry) —
   several of this project's errors look like ordinary bugs and have a
   documented cause. Check here before spending an hour debugging
   something already solved once.

Everything else in `docs/` — the ADRs, the `explanation/` pages, the
`write-rules/` per-aggregate behavior — you'll reach by link from these
four or from `CLAUDE.md`'s own index, as the task in front of you needs it.
Reading all of `docs/` cover to cover before writing code is not the goal;
knowing where to look is.

## Day 2-3: one real change, one vertical slice

Don't start on a large feature or a refactor first. Per this project's
working style (`CLAUDE.md`, "Working style"), work happens in vertical
slices — one entity, migration through UI, before starting a dependent
one. A first task should be small enough to finish inside that shape:

- **If it's a new Action**: `docs/how-to/add-an-action.md` is the recipe,
  start to finish, with the checklist for "does this even need to be an
  Action" up front.
- **If it's a bug fix**: read `docs/reference/write-rules/` for the
  aggregate you're touching first — refusals and races are often
  deliberate, not oversights, and "fixing" one without reading why it's
  there is how a regression gets reintroduced.
- **Either way**: `docs/how-to/run-the-tests.md` before you open a PR.
  `pint --test`, `phpstan analyse --memory-limit=1G` (the memory limit is
  required, not optional — the container default crashes Larastan's
  workers and reports a fake error), and `pest` all need to pass, and
  Concurrency tests specifically must never run under `--parallel`.

## Whatever you do, don't

- Edit a merged migration. Add a new one — `docs/reference/coding-conventions.md`
  and the "Working style" section of `CLAUDE.md` both cover why.
- Introduce a repository layer. Eloquent is the repository here, by
  deliberate choice, not oversight.
- Commit or push without being asked. This project's convention is to ask
  first — see `CLAUDE.md`.
- Assume an error is new. Check `docs/how-to/troubleshooting/` first; if
  it's genuinely not there once you've solved it, add it — that's how the
  index stays useful for the next person.

## Who to ask

For anything this doc doesn't answer, the router in `CLAUDE.md` almost
certainly points somewhere — that file is designed to be read before
anything architectural, by a person or an agent, for exactly this reason.
