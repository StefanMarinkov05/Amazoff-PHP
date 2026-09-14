# ADR-0017: Pest browser tests, not standalone Playwright

Status: Accepted
Date: 2026-09-09 · Deciders: Stefan Marinkov

> **Note (2026-09-09):** written against Pest 4; [ADR-0018](0018-adopt-pest-5.md)
> upgraded the whole test stack to Pest 5 / PHPUnit 13 the same day, before
> the browser suite's specs were written. The decision here is unchanged —
> `pestphp/pest-plugin-browser` over standalone Playwright, a dedicated
> no-refresh-trait database, local-runbook-only 3DS — only the version
> moved: the plugin is `^5.0` (needs `pest ^5`, `playwright` 1.62.1), not
> the frozen 4.3.x line. Read "Pest 4" below as "the Pest browser plugin".

## Context

Three things are provably untested before deployment, each already flagged
in `docs/reference/testing/`:

- **§37 #19 responsive** (desktop + mobile) — covered only by manual
  browser-MCP screenshots (`testing/browser-testing.md`): one browser, no
  automation, drifts the moment a Blade view changes.
- **3-D Secure** — `testing/stripe-testing.md` §"3D Secure is not
  exercised": card `4000 0025 0000 3155`, the browser-side SCA redirect,
  driven by hand exactly once (2026-09-04) with no repeatable test.
- **No system-simulation / E2E layer** — nothing walks
  guest → cart → checkout → pay → webhook → ship → track through the real
  storefront and panel pages in one run. The repo has 1194 headless
  Feature/Unit tests plus the Concurrency suite; none of them drives a real
  browser.

All three need a real browser process. The question this ADR settles is
*how* that browser is driven and where its tests live — nothing was built
for browser testing when ADR-0009/0010 set the testing and CI shape.

`playwright` is installed as a plugin (`~/.claude/plugins/`), and
`@playwright/mcp` on the host is what drove the manual passes. Two routes
to an automated suite:

- **Standalone Playwright** — its own `@playwright/test` runner, its own
  `playwright.config.ts`, its own CI job, its own reporters. A second test
  toolchain alongside Pest.
- **Pest 4 browser plugin** (`pestphp/pest-plugin-browser`, v4.7.x line,
  needs `pest ^4.3.1` — the repo has `^4.7`) — browser tests written as
  `it()` blocks in `tests/Browser/`, one runner, factories and DB
  assertions inline in the same test.

## Decision

**Pest 4 browser tests (`pestphp/pest-plugin-browser`), in a dedicated
`tests/Browser/` testsuite.**

### Why not standalone Playwright

`src/CLAUDE.md` and `reference/coding-conventions.md`'s testing-discipline
section commit this project to one test layer: a test proves something
*ours*, exercises the real Action/webhook/UI, and asserts against the
database it just wrote. A standalone Playwright suite would put browser
assertions in a different language, a different runner, and a different CI
job from every other test — and the E2E lifecycle test specifically needs
to `factory()` a catalogue, drive the browser, then assert on `orders`,
`payments`, and `payment_events` rows in the same test body. The Pest
plugin keeps that in one file; standalone Playwright would need a separate
DB-assertion shim or an HTTP callback into the app.

The cost is that the plugin is younger than `@playwright/test` and its API
surface is smaller. That is acceptable — the suite needs `visit()`, CSS
selectors, `click`/`type`/`fill`, `assertSee`, console-message capture,
viewport resize, and `evaluate()` for the responsive node-walk. All are
present.

### The Browser testsuite does not use `LazilyRefreshDatabase`

`src/phpunit.xml` runs the default suite with `SESSION_DRIVER=array`,
`CACHE_STORE=array`, and the isolated `amazoff_test` DB, with
transactional rollback per test. **A real browser is a separate process**
and cannot share an array session or an open transaction with the test
process — it reads the app over HTTP against the app's own `.env`
(`SESSION_DRIVER=database`, real DB).

So the Browser suite follows the `tests/Concurrency` precedent (ADR-0008's
suite, ADR-0010's separate CI job): **no refresh trait, its own database
(`amazoff_browser`), truncated per test, never run `--parallel`.**
Catalogue fixtures are the documented "no real creation event" exception —
company inventory, seeded by factories/`CatalogueReferenceSeeder`;
everything else (roles, permissions, carriers, orders, payments) goes
through the real seeders and the real Actions, no side doors.

### Real-Stripe 3-D Secure is a local runbook, not CI

`how-to/use-ci.md` is categorical: no Stripe, Econt, or Speedy secrets in
CI, ever. `ThreeDSecureTest` needs the real Stripe client and a live
`stripe listen` forwarding webhooks — it cannot run in `ci.yml`. It stays
`->skip()` in CI with a message that names the precondition, and is
documented as a local runbook in `testing/stripe-testing.md`, matching how
the 2026-09-04 manual 3DS record was made. `ResponsiveTest` and
`SmokeTest` — no Stripe — do run in CI as a new `test-browser` job.

A separate secrets-gated `e2e.yml` workflow was considered and rejected for
now: it needs a `stripe` GitHub environment with real keys, which is more
standing secret exposure than a once-per-release local run is worth.
Reconsider if 3DS regressions start reaching `main`.

## Consequences

- New dev dependency `pestphp/pest-plugin-browser` in `src/composer.json`.
- A `playwright` service in `docker-compose.yml` (mirroring how `vite` is a
  separate `node:22-alpine` service) — `docker/php/Dockerfile` has no Node,
  and the browser binaries and system libs do not belong in the `app`
  image.
- `amazoff_browser` is created alongside `amazoff_test`
  (`docker/mysql/init/`), and CI's `test-browser` job boots what CI boots
  nothing of today: a background app server bound to the `APP_URL` the
  specs use, `npm run build` (an unstyled page makes every overflow check
  a false pass), and a minimal seed.
- `APP_URL` must exactly equal the browser's base URL and
  `SESSION_SECURE_COOKIE` must be `false` for plain-HTTP browsing —
  signed URLs, Livewire's update endpoint, and Stripe's 3DS `return_url`
  are all computed against `APP_URL`. Recorded in
  `how-to/deploy-and-host.md` and the browser env file.
- The stale DB name `online_shop_test` in `how-to/run-the-tests.md` and
  `how-to/use-ci.md` is corrected to `amazoff_test` in the same change
  (the real name everywhere: `phpunit.xml`, `ci.yml`,
  `docker/mysql/init/01-test-database.sh`).
- If the team later decides the browser suite should be standalone
  Playwright after all — or that 3DS belongs in a gated CI workflow — that
  is a new ADR superseding this one, not an edit here.

## Alternatives rejected

**Standalone Playwright (`@playwright/test`).** Rejected for the
one-test-layer reason above, not on capability. Reconsider if the Pest
plugin's API proves too thin for a spec the suite actually needs.

**Keep manual browser-MCP screenshot passes.** This is the status quo and
the reason the three gaps are still open — it drifts, it is one person's
afternoon, and it produces no CI signal.

**Run browser tests against `amazoff_test` with the default suite.**
Impossible: the array session and open transaction cannot be seen by a
separate browser process. The dedicated DB is not a preference, it is the
only shape that works.
