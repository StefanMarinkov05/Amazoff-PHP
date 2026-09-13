# Troubleshooting

Errors that have already cost someone an afternoon, and what stops each one
coming back.

Every entry carries five parts: the **symptom** as it actually appears, the
**cause**, the **fix**, **why it recurs** — because an error that only happened
once does not need a document — and **prevention**, the change that stops it
happening again rather than the one that got past it this time.

Entries are added by whoever solves the problem, in the same PR as the fix. An
entry whose prevention has since been automated stays, with the guard named, so
nobody removes the guard without knowing what it was for.

This file used to be one flat, symptom-indexed list. It grew past the point of
being scannable, so it is now split by area under
[`troubleshooting/`](troubleshooting/) — this page is the index. When adding a
new entry, find the file whose area it belongs to (or propose a new one, if it
genuinely doesn't fit any existing area) rather than appending here.

## Index

- **[data-and-factories.md](troubleshooting/data-and-factories.md)** —
  factories, seeders, and fixtures writing data that looks right until
  something reads it back: Blueprint regeneration, silent truncation,
  discarded attributes, production-seeder safety, and factory fields that
  intermittently disagree with each other.
- **[concurrency-and-testing-races.md](troubleshooting/concurrency-and-testing-races.md)** —
  everything specific to `tests/Concurrency/`, `RefreshDatabase`, and what
  happens when two processes (or two `pest` runs) touch the same database at
  once.
- **[filament-admin-panel.md](troubleshooting/filament-admin-panel.md)** —
  Filament resources, forms, and the admin panel shell: generated forms
  missing schema-level rules, v4 namespace moves, reactive fields, and
  fields that silently save as `null`.
- **[ide-and-static-analysis.md](troubleshooting/ide-and-static-analysis.md)** —
  the IDE disagreeing with Larastan, Larastan disagreeing with itself, a
  scripted rewrite reporting success on broken PHP, and tests that stay
  green after the thing they claim to test was deleted.
- **[database-and-migrations.md](troubleshooting/database-and-migrations.md)** —
  MySQL vs. SQLite divergence, connection/database setup, migration
  performance, and the Spatie permission cache.
- **[assets-vite-frontend.md](troubleshooting/assets-vite-frontend.md)** —
  Vite, Tailwind, webfonts, and Alpine: a page that renders but the asset
  pipeline or its JavaScript didn't do what it looks like it did.
- **[infra-and-environment.md](troubleshooting/infra-and-environment.md)** —
  the container/host boundary: file ownership, Docker networking, a stray
  background process, a shell rewriting a path before Docker sees it.
- **[auth-and-sessions.md](troubleshooting/auth-and-sessions.md)** —
  security guarantees that look satisfied but aren't re-checked on the
  request that matters, plus the CI test gap that exposed one of them.
- **[payments-and-security-tooling.md](troubleshooting/payments-and-security-tooling.md)** —
  Stripe account-mismatch tooling traps, and running OWASP ZAP against this
  app.

## Finding an entry by symptom

If you don't know which area an error belongs to, grep across all of them:

```bash
grep -rn "your error text" docs/how-to/troubleshooting/
```
