# How to start a session

A prompt to paste at the beginning of a Claude Code session on this project,
and the reasoning behind what it asks for.

The prompt is the block below. Everything after it explains why each part is
there, so it can be edited rather than copied blindly as it goes stale.

---

## The prompt

```
Read these before doing anything, in order:

1. CLAUDE.md — architecture and security rules. These override defaults and
   are not up for negotiation. If something I ask for contradicts an accepted
   ADR, say so rather than quietly diverging.
2. README.md — the app lives in online-store/, not at the repo root, and
   everything runs through Docker.
3. docs/README.md — how docs/ is organised and what belongs in each folder.
4. docs/reference/specification.md — §37 is the contract being graded. The
   implementation-standards table at the bottom is the current status.
5. docs/explanation/tech-stack-overview.md — what is actually built versus
   merely installed.
6. docs/changelog/CHANGELOG.md, the Unreleased section — recent work and what
   is still open.

Then, only as the task requires:

- docs/adr/ — a decision and its rejected alternatives. Read the one covering
  the area you are about to touch. Do not re-litigate an accepted ADR.
- docs/reference/schema/schema.md and permissions.md — the schema and the
  authorization catalogue, as facts rather than reasoning.
- docs/how-to/troubleshooting.md — read BEFORE proposing a fix for any error.
  Several errors this project produces look like ordinary bugs and are not.
- docs/how-to/run-the-tests.md — before running or writing tests.
- CONTRIBUTING.md — before committing or opening a PR.

How I want you to work:

- Verify against the running Docker stack, not by reading code. php -l proves
  syntax, phpstan proves types, neither proves behaviour. The gate is:
    docker compose exec app ./vendor/bin/pint --test
    docker compose exec app ./vendor/bin/phpstan analyse --memory-limit=1G
    docker compose exec app ./vendor/bin/pest
- IDE diagnostics reporting Laravel or Filament classes as undefined are
  noise — vendor/ is in the container, not on the host. Larastan in the
  container is the authority. See troubleshooting.md.
- One vertical slice at a time, atomic commits, each one green on its own.
- Do not commit or push unless I ask. I write my own commit messages and I do
  not want Co-Authored-By lines.
- Ask before anything hard to reverse: schema changes, force pushes, deleting
  branches, editing a merged migration.
- Tell me when you are unsure rather than producing something plausible. A
  wrong authorization check and a right one look identical.

Docs style: dry and factual. No marketing tone, no instructions addressed to
the reader in reference or explanation docs. Record why, not just what — an
entry that says what was fixed without why it recurs is half an entry.
```

---

## Why each part is there

### Why the reading order is fixed

`CLAUDE.md` first because it is the only file whose rules override the model's
defaults, and because the two facts most likely to waste a session are in it
and in `README.md`: the application is in `online-store/`, and nothing runs
outside Docker. A session that misses those spends its first several tool calls
looking for `artisan` at the repository root and running `php` on the host.

The specification is fourth rather than first because §37 is only meaningful
once the layout is known. Its implementation-standards table is the part that
goes stale fastest and is worth re-reading even in a continued session.

### Why ADRs are conditional rather than up front

There are six and they are long. Reading all of them at the start of every
session spends context on decisions the task will not touch. Reading the one
that covers the area about to change is the useful half — and the instruction
not to re-litigate matters because an LLM asked to review a decision will
usually find something to say about it, which is not the same as the decision
being wrong.

### Why troubleshooting.md is called out separately

`CLAUDE.md` already requires it before proposing a fix. It is repeated here
because it is the instruction most often skipped under time pressure, and
because several entries describe errors that look like ordinary bugs: a green
Larastan run against red IDE diagnostics, a factory that passes every static
check and fails on insert, a permission change that saves and does not take
effect.

### Why "verify against the running stack" is stated twice

It is in `CLAUDE.md` and repeated in the prompt because it is the single
instruction that most changes output quality on this project. The failure it
prevents is specific: static analysis is green, the code reads correctly, and
the behaviour is wrong. Every bug in `troubleshooting.md` that cost an
afternoon had a green static run at the time.

### Why the commit instruction is explicit

Left unstated, a coding agent will offer to commit, and often will. The
project's commit messages are written by hand and reviewed as part of
implementation standard #3.

## Model and effort

`docs/how-to/choose-a-model.md` is the full table. The short version for a
session prompt:

| Session is mostly | Model | Effort |
|---|---|---|
| Business logic, authorization, payments, couriers | Opus 5 | `xhigh` |
| A vertical slice, debugging, ADRs and docs | Opus 5 | `high` |
| Filament resources, Blade, enum and factory sweeps | Sonnet 5 | `high` or `medium` |

Set it before the session rather than mid-way: switching models does not
re-run the reading above, and the second model inherits a context assembled
under the first one's judgement about what was worth reading.

The one line worth remembering from that document: **anything where being
wrong is expensive and not obviously wrong gets Opus 5 at `xhigh`.** A
miscalculated VAT total does not announce itself in review; a Blade template
that renders badly does.

## What not to put in the prompt

**The architecture rules themselves.** They are in `CLAUDE.md`, which is loaded
automatically. Repeating them wastes context and creates a second copy to keep
in step.

**A task description.** This prompt establishes how to work; the task is a
separate message. Mixing them means re-pasting the whole thing for each new
piece of work.

**Credentials.** The seeded demo passwords are in `reference/permissions.md`
and are non-production only. Real keys live in `.env`, which is gitignored, and
implementation standard #17 keeps them out of `.env.example` too.
