# ADR-0009: Code coverage — collected, not gated

Status: Accepted
Date: 2026-08-17 · Deciders: Stefan Marinkov

## Context

No coverage driver was configured anywhere in the stack. CI set
`coverage: none` explicitly, and neither Xdebug nor PCOV was installed in the
local `app` image, so `pest --coverage` failed outright in both places.

This project's own testing philosophy already has a settled position on what
a coverage number is worth. `reference/write-rules/concurrency.md`: *"A row here
counts as verified only if the test has been observed failing with its
mechanism deleted... a test that has never failed is not evidence."* That
same page verifies the enum transition matrices by mutation rather than
coverage, for the stated reason that a matrix can be "100% covered" by tests
that never assert a single wrong transition. Nothing about adding a coverage
tool should quietly contradict that.

What coverage *is* good for, on this codebase's own terms, is different and
narrower: a coarse, cheap signal that catches a line nobody wrote a scenario
for at all — a gap in the hand-built matrices themselves (`reference/write-rules/product.md`,
`reference/write-rules/cart.md`, `reference/write-rules/concurrency.md` etc.), not a substitute for them.

## Decision

### PCOV

Measured against this suite specifically, not assumed from general PHP
community benchmarks, per Pest installed temporarily, timed, then removed:

| Suite | PCOV | Xdebug (`mode=coverage`) | Difference |
|---|---|---|---|
| `tests/Concurrency/` (7 files, 13 tests) | 184.3s | 208.2s | +13% |
| `tests/Feature/Actions/Cart/` + `Support/` (in-process only) | 90.5s | 123.0s | +36% |

Both drivers agreed on the coverage percentage in both runs, which is the
sanity check that they were measuring the same thing. The gap is smaller on
`tests/Concurrency/` than the in-process suite, not larger — most of that
suite's wall-clock time is barrier-waiting and subprocess-booting outside
the instrumented process (per the blind spot below), which dilutes the
driver's relative cost rather than compounding it. The honest number is
Xdebug adding roughly a third to a fast, purely in-process run — real,
worth avoiding for a stats-only use case, but not the order-of-magnitude
difference "Xdebug is slow" folklore implies.

`pcov.directory`/`pcov.exclude` scope collection to the application source,
excluding `vendor/` and `tests/`, so the report measures the application
being exercised rather than the suite measuring itself.

The default 128M `memory_limit` is not enough to assemble a full-project
report regardless of driver — measured: the full suite's 418 tests passed,
then report generation exhausted 128M building `coverage.php`.
`docker/php/conf.d/cli-memory.ini` raises it to 1G.

### Reported, not gated

No `--min` threshold, in CI or anywhere else. Originally `pest --coverage`
ran in CI and produced a Clover XML artifact; ADR-0010 removed coverage
collection from CI entirely once `test` became a 2-shard matrix, rather
than merge two partial reports for a number nobody was gating on.
`how-to/run-the-tests.md` and `reference/coverage.md` have the commands to
generate it locally, on demand.

## Consequences

+ A cheap secondary signal, additive to the scenario-matrix docs rather than
  competing with them. Not hypothetical: the first full-project run found
  three genuinely untested branches in one pass — `ForceDeleteProduct`'s
  refusal when a product has reviews, `RemoveProductImage`'s authorization
  check (every existing test passes a null actor), and `ReleaseStock`'s
  `quantity < 1` guard, which `ReserveStock`'s equivalent guard already had
  and this one didn't. None were near any concurrency boundary — plain gaps
  no scenario doc had named yet. `reference/coverage.md` has the full
  per-class breakdown.
+ Negligible slowdown — PCOV's whole design goal.
+ No new gate that rewards a shallow test hitting a line without asserting
  anything about it, which this project has already decided not to trust.

− **A race contributes exactly zero to the number, whatever the class's
  overall percentage says.** `tests/Concurrency/*` spawns real `php`
  subprocesses via `Symfony\Process` to run the Action under test — the two
  workers in `PublishProductConcurrencyTest.php`, for instance, execute
  `UpdateProduct`/`RemoveProductVariation` entirely outside the `pest`
  process PCOV instruments, and running that file alone measures **0.0%** on
  both classes. Verified, not assumed: isolating
  `ReserveStockConcurrencyTest.php`'s own race test the same way also
  measures 0.0% on `ReserveStock`.

  A class's *overall* percentage can still look high regardless, because
  most concurrency-tested classes also have a same-process feature test, or
  — as `ReserveStockConcurrencyTest.php` does — a same-process sequential
  companion test in the same file ("lets the second reservation see the
  first once it commits"), which measured `ReserveStock` at 92.3% on its
  own. The number is not *systematically low* on these classes; it is
  **silent about the race specifically**. A high percentage says the code
  ran through *some* path, never that the interleaving the race test proves
  was exercised. This is the same failure mode `reference/write-rules/concurrency.md`
  already names for a different reason (a test that never failed is not
  evidence) — neither a passing race test nor a high coverage number, alone
  or together, proves what the other proves.

  The clearest example is `AddToCart`/`MergeGuestCart`: 97.1%/95.5% from
  the feature suite alone, and in both classes the single uncovered line is
  the retry inside `catch (UniqueConstraintViolationException)` — the exact
  line that only runs when two requests collide on the same insert. It is
  not untested; `AddToCartConcurrencyTest.php`/`MergeGuestCartConcurrencyTest.php`
  prove it by deletion. It is invisible to this number because the collision
  only happens across two real processes.
− A covered line proves execution, not a correct outcome. The number cannot
  replace `reference/write-rules/product.md`, `reference/write-rules/cart.md`, or
  `reference/write-rules/concurrency.md`, and is not meant to.

## Alternatives rejected

- **Xdebug.** More capable — coverage plus IDE step-debugging — at a
  measured ~13–36% slowdown depending on how much of the run is actual PHP
  execution versus external waiting (table above). Real, not dramatic.
  Revisit if interactive debugging becomes a real need; nothing here blocks
  adding it later, and the cost of switching is small.
- **Gating CI on a coverage minimum.** Rejected for the same reason the
  project already rejects trusting a passing test without deletion-proof: a
  number can be inflated by shallow assertions, and it is provably blind to
  the one thing the concurrency suite exists to prove — a percentage cannot
  fall because a race went unverified, since the race was never in the
  denominator. A gate built on a signal that cannot see the failure it is
  meant to catch is decoration, not a safeguard.
