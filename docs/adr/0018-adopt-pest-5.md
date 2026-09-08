# ADR-0018: Upgrade to Pest 5 / PHPUnit 13

Status: Accepted
Date: 2026-09-09 · Deciders: Stefan Marinkov

## Context

The test stack was Pest 4.7 on PHPUnit 12.5: 1194 Feature/Unit tests, a
19-file Concurrency suite, hand-sharded CI ([ADR-0010](0010-ci-parallelization.md)).

Pest 5 shipped 2026-07-21 and is on 5.1.x by the time of this decision. Two
of its features address pain this project has already written down:

- **TIA (Test Impact Analysis)** — records test↔file dependencies from
  coverage data and replays only the tests a change can affect. Pest's own
  figure is "a 10-minute Laravel suite in ~4 seconds." This suite's wall
  time is the reason ADR-0010 exists.
- **Time-balanced sharding** — splits CI by measured execution time rather
  than test count. ADR-0010 documents at length how error-prone sizing
  shards by hand was (two false leads, PASS-line-timestamp traps). Pest 5
  does automatically what that ADR did by inspection.

Plus, adopting browser testing now ([ADR-0017](0017-adopt-pest-browser-testing.md))
means either its plugin is on the 4.3.x line — frozen, `pest ^4.4.5` — or on
5.x with everything else. Splitting the test framework across two major
versions to avoid one upgrade is the worse outcome.

Secondary gains, weighed but not decisive: a first-party PHPStan plugin that
understands `it()`/`expect()` chains, a Rector plugin for test modernisation,
an Agent plugin (agents run tests with factories/DB/fakes and drive
browsers), and new validation matchers.

## Decision

**Pest 5.1, PHPUnit 13.3, all Pest-maintained plugins on 5.x, paratest 7.24.**

The upgrade requirements and their cost here:

- **PHP 8.4** — already required (`composer.json` was raised to `^8.4` when
  `symfony/*` v8.1 landed in the lockfile). Zero cost.
- **PHPUnit 13.** Its backward-compatibility breaks were checked against
  this suite by grep before the upgrade: `Assert::isType()`,
  `assertContainsOnly()` / `assertNotContainsOnly()`, `#[CoversNothing]` on
  methods, `#[RunClassInSeparateProcess]`, `--dont-report-useless-tests`,
  the hard-deprecated `any()` matcher — **zero occurrences.** The suite
  needed no test changes.
- **Coordinated `composer update`** of `pest`, `pest-plugin`,
  `pest-plugin-{browser,laravel,arch,mutate,profanity}`, `phpunit/phpunit`,
  `brianium/paratest`, `nunomaduro/collision`, pulling
  `phpunit/php-code-coverage` 12 → 14.

### Verified

The full suite was run on Pest 4.7 immediately before the upgrade and on
Pest 5.1 immediately after, same machine, same command:

| | Pest 4.7 / PHPUnit 12.5 | Pest 5.1 / PHPUnit 13.3 |
|---|---|---|
| Feature + Unit (`--parallel --processes=4`) | 1194 passed, 2930 assertions | 1194 passed, 2930 assertions |
| Concurrency | (pre-existing flakiness, see below) | unchanged |
| Pint | clean, 647 files | clean, 647 files |
| Larastan (`--memory-limit=1G`) | no errors | no errors |

No test file was modified for the upgrade. The `tests/Concurrency`
suite's known non-deterministic cases (documented at `actions.md` ~L504 for
`DeleteProductCategory`, and in `scratchpad`) are unchanged by this — they
were flaky on Pest 4 for the same timing reasons.

### TIA is adopted incrementally, not switched on blind

TIA caches results keyed by coverage data; it is only sound if coverage is
being collected, and [ADR-0009](0009-code-coverage.md) deliberately keeps
coverage *out* of the default local and CI runs (PCOV is installed,
`pest --coverage` is opt-in). So TIA is enabled where coverage already runs
or is cheap to add — the local `--dirty` developer loop and a dedicated CI
lane — not bolted onto the hand-sharded matrix in the same change. The
sharding in ADR-0010 stays as-is until a follow-up measures Pest 5's
time-balanced sharding against it on this suite's real numbers.

## Consequences

- `composer.json` dev requirements: `pestphp/pest ^5.1`,
  `pestphp/pest-plugin-browser ^5.0`, `pestphp/pest-plugin-laravel ^5.0`,
  `phpunit/phpunit ^13.3`. The arch/mutate/profanity plugins move with
  `pest` as transitive deps.
- The browser suite's pinned Playwright version follows the plugin:
  `pest-plugin-browser` 5.0.1 requires `playwright` 1.62.1 (was 1.59.1 on
  the 4.3.x line). Pinned in `package.json` and `docker/php/Dockerfile`.
- CI's PHP setup installs from `composer.lock`, so the matrix picks the new
  versions up with no workflow change. A TIA lane and any move to
  time-balanced sharding are separate, measured changes.
- `src/CLAUDE.md` / `.ai/guidelines` note the Pest major version where they
  describe the test commands.
- Reverting means pinning the whole list back to the 4.x / 12.x line in one
  `composer update` — the lockfile change is the whole change, since no
  application or test code depends on a Pest 5 API.

## Alternatives rejected

**Stay on Pest 4.7.** Forgoes TIA and time-balanced sharding — the two
features that directly retire ADR-0010's documented cost — and forces the
new browser plugin onto its frozen 4.3.x line. The only saving is the
upgrade itself, which was verified to need no test changes.

**Pest 5 core, browser plugin held at 4.3.1.** Not possible:
`pest-plugin-browser` 4.3.1 requires `pest ^4.4.5`. A split across two Pest
majors is strictly worse than either coherent choice.

**Turn TIA on for the full CI matrix now.** Rejected as scope. TIA needs
coverage data to be sound, ADR-0009 keeps coverage opt-in, and ADR-0010's
shard sizing is load-bearing — changing all three at once would make a
regression impossible to attribute. Incremental adoption, each step
measured, is the same discipline ADR-0010 itself used.
