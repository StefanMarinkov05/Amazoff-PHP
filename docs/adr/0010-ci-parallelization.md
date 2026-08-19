# ADR-0010: CI split into parallel, hand-sharded jobs; coverage dropped from CI

Status: Accepted
Date: 2026-08-17 · Deciders: Stefan Marinkov

## Context

CI ran as one job, one step after another: setup, Pint, Larastan, then the
whole Pest suite (434 tests) in a single process, taking roughly 4 minutes
end to end.

**Measured, not assumed**, from a run's own job log
(`gh run view <id> --log`):

- `tests/Concurrency/*` (9 files, 19 tests) took 281s of wall-clock time.
  `tests/Unit` + `tests/Feature` (415 tests) took 375s in the original
  single-job run, of which the `pest --coverage` step itself was ~85s once
  isolated from setup steps in a later, already-split run. The concurrency
  suite is under 5% of the test count and a large share of the time.

### Two false leads, corrected before sizing anything

Sizing shards from a naive PASS-line timestamp diff produces confidently
wrong numbers, twice over, and both are worth recording so the mistake
isn't repeated:

1. **Attributing a gap to the wrong file.** Pest prints a file's `PASS`
   line *after* its last test finishes, so the wall-clock gap between two
   consecutive `PASS` lines belongs to the file whose tests just ran, not
   the one about to start. Diffed naively, this first pointed at
   `tests/Feature/Filament/ReportsDomainFailuresTest.php` (5 tests, each
   individually self-reporting ~0.05s) as a ~33s outlier. Checking each
   test's own self-reported duration inside the file — not the gap before
   its `PASS` line — showed all five at sub-0.1s. The real ~33s belongs to
   `tests/Feature/RolePermissionTest.php`, the file that runs immediately
   after it.
2. **A phantom environment-specific cost.** Before catching (1), the same
   ~30s gap was chased as a `RefreshDatabase` schema-load cost specific to
   local Docker Desktop — plausible on its own (a real `mysql <
   mysql-schema.sql` load measured ~20-37s locally) and briefly written up
   as such in `troubleshooting.md`, but wrong: it doesn't explain why the
   cost followed a specific file (`RolePermissionTest`) rather than
   "whichever test runs first," and CI's own log showed its first test at
   0.02s, not ~30s. `troubleshooting.md`'s entry was corrected once (2) was
   found; the local schema-load timing was real but unrelated to this
   question.

**What `RolePermissionTest` is actually doing**, once correctly isolated:
its `beforeEach` calls `forgetCachedPermissions()` and reseeds
`PermissionSeeder`, `RoleSeeder`, and `UserSeeder` before *every* test, not
once per file — deliberate, per the file's own comment (the permission
registrar caches for 24h; without forgetting it, the second test in the
file resolves against the first test's already-truncated rows), but
expensive across the file's many dataset-driven tests. Not a bug.

### Per-file numbers, verified against each test's own self-reported
duration rather than PASS-line gaps

`tests/Concurrency/*`:

| File | Wall-clock | Tests |
|---|---|---|
| `AddToCartVsMergeGuestCartConcurrencyTest` | ~50s | 6 (`repeat(6)`) |
| `AddToCartConcurrencyTest` | ~8.6s | 2 |
| `MergeGuestCartConcurrencyTest` | ~8.5s | 2 |
| `ReleaseStockConcurrencyTest` | ~8.5s | 3 |
| `ReserveStockConcurrencyTest` | ~8.3s | 3 |
| `ForceDeleteProductVariationConcurrencyTest` | ~8.2s | 1 |
| `MainProductImageConcurrencyTest` | ~8.2s | 1 |
| `PublishProductConcurrencyTest` | ~8.2s | 1 |

One file — the only one with `->repeat(6)` — is over half the suite's
test-execution time on its own. Every other file costs about the same
regardless of assertion count, because that cost is process-boot plus
wall-clock barrier wait (`explanation/concurrency-and-locking.md`), not the
assertions themselves.

`tests/Unit` + `tests/Feature` (~84s total, matching the CI-measured ~85s):

| File | Wall-clock |
|---|---|
| `RolePermissionTest` | ~32.8s |
| `Catalogue/CreateProductTest` | ~6.8s |
| `Catalogue/ConcurrentProductEditTest` | ~6.4s |
| `Catalogue/ForceDeleteProductVariationTest` | ~6.0s |
| `Catalogue/DeleteProductTest` | ~5.1s |
| `FactoryTest` | ~4.0s |
| `Cart/UpdateCartItemQuantityTest` | ~3.7s |
| `RoleResourceTest` | ~3.6s |
| `Catalogue/ProductImageTest` | ~3.3s |
| `Unit/Enums/TransitionMatrixTest` | ~2.9s |
| all remaining 12 files | ~9.7s combined |

`RolePermissionTest` alone is over a third of this suite's time — a
different shape of outlier than the concurrency suite's `repeat(6)` file
(one expensive `beforeEach` repeated many times, not one file with many
process-boot-bound tests), but the same shape of problem: an even split by
file count would strand it in whichever shard it landed in.

## Decision

### Three parallel jobs, no coverage collection

`lint` (Pint, Larastan — no database needed), `test` (`tests/Unit` +
`tests/Feature`, 2-shard matrix), `test-concurrency` (`tests/Concurrency`,
3-shard matrix). GitHub Actions runs jobs and matrix entries in parallel by
default, so seven parallel runners replace one sequential job; total
compute is roughly unchanged, wall-clock on the critical path drops to
whichever shard is slowest plus fixed per-job overhead.

Coverage (`--coverage --coverage-clover`) is dropped from CI entirely,
rather than merged across `test`'s two shards or kept on one shard only.
Once `test` is sharded, no single shard's report is the number
`reference/coverage.md` and ADR-0009 describe — a merge step
(`phpcov merge` or similar) is real infrastructure to add and maintain for
a number that was already reported-not-gated (ADR-0009) and had no
regular reader of the CI artifact. Generate coverage locally
(`docs/reference/coverage.md` has the command) instead.

### Both suites are 2–3-shard matrices, hand-partitioned by measured time

Not split evenly by file count, and not auto-sharded by a tool — sized
directly against the tables above, isolating each suite's one dominant
file into its own lighter-weight neighborhood rather than letting it land
wherever alphabetical or file-count order happens to put it:

- **`test-concurrency`**: shard a is `AddToCartVsMergeGuestCartConcurrencyTest`
  alone (~50s); shard b and c split the remaining seven files
  (~34s / ~25s).
- **`test`**: shard 1 is `RolePermissionTest` plus three lighter files
  (~42.6s); shard 2 is the remaining 17 files (~41.4s).

Each shard gets its own runner and its own MySQL service container —
services are not shared across jobs or matrix entries. `how-to/use-ci.md`
has where a new test file goes.

## Consequences

+ Concurrency and Feature/Unit suites' wall-clock cost no longer sit on
  the same critical path as `lint` or each other; all three run side by
  side.
+ Sharding by measured time rather than file count means shards finish
  close together instead of one silently carrying most of the suite's
  time — true for both suites, for two different underlying reasons (one
  process-bound, one a per-test `beforeEach`).
+ Coverage collection stops costing CI time for an artifact nothing
  regularly reads, without losing the report — it still generates locally
  on demand.
− More jobs to read on a PR's checks list (seven, up from one), and more
  YAML — five `services.mysql` blocks total instead of one — to maintain
  than a single job.
− Both shard partitions are snapshots of measured timing as of
  2026-08-17. They go stale as tests are added or change shape (a new
  `repeat()`, a new file with a `RolePermissionTest`-style `beforeEach`).
  `how-to/use-ci.md` has the rule for where a new file goes and when it's
  worth re-measuring — sharding is optimizing a number nobody watches
  per-commit, so the partition is corrected when it visibly drifts, not
  kept exactly current.
− No coverage number is visible from CI runs going forward. Anyone wanting
  the current figure regenerates it locally rather than reading a job's
  artifact — a real cost for the (so far, hypothetical) case where someone
  wants a fast coverage check without a local Docker environment running.

## Alternatives rejected

- **Merging coverage across `test`'s two shards.** Technically possible
  (`phpcov merge`, or Pest's own coverage merge support) but adds a real
  dependency between the two shards (the merge step has to wait for both)
  and ongoing maintenance for a report ADR-0009 already established
  nothing gates on and, in practice, nothing was regularly reading from
  the CI artifact either. Revisit if that changes.
- **Keeping coverage on one `test` shard only.** Rejected specifically
  because the resulting artifact would look like a full-project report
  while only covering roughly half the files — misleading is worse than
  absent for a number people might screenshot or link without checking
  its scope.
- **Splitting shards evenly by file count, in either suite.** Rejected
  directly by both per-file timing tables: an even split would still
  strand `AddToCartVsMergeGuestCartConcurrencyTest` or `RolePermissionTest`
  alone in whichever shard it landed in, which is no better than fewer,
  larger shards.
- **Not sharding `test` at all, only `test-concurrency`.** Considered
  first, on the reasoning that `tests/Unit`+`tests/Feature`'s ~85s was
  dominated by aggregate round-trips across many files rather than one
  outlier — true of the *suite* in general, but not true of
  `RolePermissionTest` specifically once correctly isolated (see the false
  leads above). Once that file's real cost was known, sharding `test`
  gained the same justification `test-concurrency` already had.
