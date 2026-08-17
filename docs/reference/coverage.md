# Test coverage — PCOV, full suite

Facts as of 2026-08-17. PCOV only — Xdebug was benchmarked against it and
removed; ADR-0009 has the numbers and the reasoning for keeping PCOV. This
page is line coverage, not scenario coverage: `write-rules/product.md`,
`write-rules/cart.md`, and `write-rules/concurrency.md` are what actually
prove a behaviour; this page is where a line ran at all.

**Overall: 57.5%.** 429 tests, 1196 assertions, full suite including
`tests/Concurrency/`.

This page is the numbers; `coverage-html/` (gitignored, not produced by CI —
`how-to/use-ci.md`) is the file-by-file, line-by-line report they're read
off. Regenerate it locally with `pest --coverage-html=coverage-html` and
open `coverage-html/index.html`.

## Reading a number below 100%

Two different things produce one, and they mean opposite things:

**Proven elsewhere, invisible to this number.** `tests/Concurrency/*` runs
the Action under test inside a separate `php` subprocess PCOV never
instruments (ADR-0009). A line that only executes during a two-process race
shows as uncovered here even when a concurrency test verifies it by
deletion. Not a gap — a blind spot in the tool, not the suite.

**Actually untested.** Nothing anywhere in the suite executes the line.
Genuinely worth a test.

Every row below states which one it is.

### Correcting for the blind spot

For a class where every uncovered line is concurrency-proven, the reported
number understates what's actually verified. A corrected figure — crediting
lines a concurrency test proves by deletion, not just what PCOV saw execute
— is derivable from the reported percentage alone:

```
T = U / (1 − reported%/100)          total executable lines, derived
corrected% = reported% + (C / T × 100)
```

where `U` is the uncovered line count PCOV reports and `C` is how many of
those are concurrency-proven (`C ≤ U`, both read off the table below).
**Whenever every uncovered line is accounted for (`U = C`), corrected% is
always exactly 100%** — the gap is fully explained, not partially. That is
the case for both `AddToCart` and `MergeGuestCart` today:

| Class | Reported | `U` | `C` | `T` (derived) | Corrected |
|---|---|---|---|---|---|
| `AddToCart` | 97.1% | 1 | 1 | 1 / (1 − 0.971) ≈ 34–35 | 100.0% |
| `MergeGuestCart` | 95.5% | 1 | 1 | 1 / (1 − 0.955) ≈ 22 | 100.0% |

`T` is derived rather than read from the HTML report — PCOV's dashboard
summary is JS-rendered, not plain text, so it isn't worth scraping just to
replace an already-exact algebraic result with the same number.

This does not mean the two-process race is provable single-process by some
other means, only that the specific line the race exercises is fully
accounted for. Forcing the same line to execute *without* two processes
turns out not to be simple here either — `addOrIncrement()`'s own
transaction rolls back on the thrown collision, so a "rival" row has to be
committed on a genuinely separate connection to survive that rollback, and
`AddToCartTest.php` runs inside `RefreshDatabase`, which hides its fixtures
from any other connection. Making that work needs the same
committed-fixtures, second-connection infrastructure `tests/Concurrency/`
already has — at which point it is that suite's job, not a quick
single-process trick.

## Cart

| Class | Coverage | Uncovered | What it is |
|---|---|---|---|
| `AddToCart` | 97.1% | line 70 | Collision retry — proven by `AddToCartConcurrencyTest.php` |
| `MergeGuestCart` | 95.5% | line 61 | Collision retry — proven by `MergeGuestCartConcurrencyTest.php` |
| `RemoveFromCart` | 100.0% | — | — |
| `UpdateCartItemQuantity` | 100.0% | — | — |

## Catalogue

| Class | Coverage | Uncovered | What it is |
|---|---|---|---|
| `AddProductImage` | 100.0% | — | — |
| `AddProductVariation` | 100.0% | — | — |
| `CreateProduct` | 100.0% | — | — |
| `DeleteProduct` | 100.0% | — | — |
| `ForceDeleteProduct` | 100.0% | — | — |
| `ForceDeleteProductVariation` | 100.0% | — | — |
| `RemoveProductImage` | 100.0% | — | — |
| `RemoveProductVariation` | 100.0% | — | — |
| `SetMainProductImage` | 100.0% | — | — |
| `UpdateProduct` | 100.0% | — | — |

## Inventory

| Class | Coverage | Uncovered | What it is |
|---|---|---|---|
| `RecordInventoryMovement` | 100.0% | — | — |
| `ReleaseStock` | 100.0% | — | — |
| `ReserveStock` | 100.0% | — | — |

## Support

| Class | Coverage |
|---|---|
| `CalculateCartTotals` | 100.0% |
| `PermissionCatalogue` | 100.0% |
| `ResolveVariationPrice` | 100.0% |

## Filament/Concerns

| Class | Coverage |
|---|---|
| `ReportsDomainFailures` | 100.0% |

## Not measured here

`Policies/*` and most `Models/*` run 0–60% — expected, since authorization
matrix coverage is `tests/Feature/RolePermissionTest.php`'s job
(`how-to/run-the-tests.md`), not per-class line count, and models are mostly
relations and casts exercised incidentally by whatever touches them.
