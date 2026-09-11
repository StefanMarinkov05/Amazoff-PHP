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

### Verified (2026-09-11): time-balanced sharding adopted, measured

The measurement this section deferred. `pest --update-shards` across
`--testsuite=Feature,Unit,Concurrency` in one run (must be one invocation —
it rewrites `tests/.pest/shards.json` scoped to whatever tests it saw,
not merged with a prior run covering a different suite): 1350 tests
passed, 549.59s sequential, real per-class timing for 146 classes.

Compared against `ci.yml`'s then-current hand-partitioned matrix — which
had already drifted since ADR-0010's original measurement, exactly the
"one shard visibly outruns the others" trigger `use-ci.md` names for a
re-balance, found by measuring rather than by someone noticing a slow CI
run:

| Job | Hand-sharded max (critical path) | Time-balanced max | Improvement |
|---|---|---|---|
| `test` (Feature+Unit, 4 shards) | 69.07s (shard 2 had grown to 76 of 126 classes) | 39.32s | **43.1%** |
| `test-concurrency` (3 shards) | 149.40s | 132.79s | **11.1%** |

`test`'s drift was the more dramatic finding: the "add to shard 2 by
default" rule `use-ci.md` used to state was, by construction, guaranteed
to regrow exactly the imbalance it was meant to avoid, and had — shard 2
carried 76 of 126 classes by the time this was measured. `test-concurrency`
was closer to balanced already, so the win is real but smaller.

**Adopted for both jobs.** `ci.yml`'s `test` and `test-concurrency` now run
`pest --shard=<N>/<total>` against a committed `tests/.pest/shards.json`,
replacing the hand-listed file-path matrices. Verified locally before
trusting it in CI: `--shard=1/4` and `--shard=4/4` each ran their expected
~30-file slice and passed; `--shard=2/3` on `tests/Concurrency` likewise.
No shard-assignment rule to maintain any more — a new test file round-robins
until the next `--update-shards`, and Pest itself prints `WARN  The
[tests/.pest/shards.json] file is out of date` in the job log when one
exists, rather than requiring a human to notice a slow shard. `how-to/use-ci.md`
has the refresh command and the full mechanism.

**TIA's local loop is not adopted yet — blocked, not deferred by choice.**
Both `--dirty` and `--tia` need `git`, and every `docker-compose.yml`
service mounts only `src/`, never the repository root's `.git` one level
up — verified by trying, not assumed: `git -C /var/www/html status` inside
the `app` container reports `not a git repository ... Stopping at
filesystem boundary`.
`how-to/troubleshooting/infra-and-environment.md` has the full symptom and
the fix (mount `.git` read-only into the affected services), not yet
applied — it needs its own verification once a stable window exists, not a
same-session change made blind on top of an already-unstable local
environment. `run-the-tests.md` is corrected to say so rather than
repeating the previously-unverified "`--dirty` is the fast local loop"
claim. TIA's cross-machine baseline sync (`--tia --baselined` /
`--refetch`, needing a git remote) is a separate, still-unmeasured step
beyond that fix, per the "each step measured" discipline below.

## Consequences

- `composer.json` dev requirements: `pestphp/pest ^5.1`,
  `pestphp/pest-plugin-browser ^5.0`, `pestphp/pest-plugin-laravel ^5.0`,
  `phpunit/phpunit ^13.3`. The arch/mutate/profanity plugins move with
  `pest` as transitive deps.
- The browser suite's pinned Playwright version follows the plugin:
  `pest-plugin-browser` 5.0.1 requires `playwright` 1.62.1 (was 1.59.1 on
  the 4.3.x line). Pinned in `package.json` and `docker/php/Dockerfile`.
- CI's PHP setup installs from `composer.lock`, so the matrix picks the new
  versions up with no workflow change. Time-balanced sharding is adopted
  and measured (see the addendum above); a TIA lane is still a separate,
  unmeasured next step.
- `src/tests/.pest/shards.json` is committed — `ci.yml`'s `test` and
  `test-concurrency` jobs read it via `--shard`. Refresh it with
  `--update-shards` (one invocation covering every sharded testsuite; see
  `how-to/use-ci.md`) whenever a job's log shows the drift warning, or
  after a batch of new test files.
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
