# Testing

Every "what did testing prove" document, grouped apart from the system-fact
files one level up (`actions.md`, `permissions.md`, …) since these change at
a different rate and for different reasons — a test result is a claim about
one date, not a standing fact about the system.

## Top-level pages

- **[acceptance-criteria.md](acceptance-criteria.md)** — §37's twenty
  mandatory criteria, each mapped to the exact test file and case name that
  proves it today. The answer to "where's the proof" for §37 #20
  specifically, without restating the ~150 files it indexes.
- **[ui-tests.md](ui-tests.md)** — every storefront and admin-panel UI test
  and what it proves, grouped by component/resource. Check before changing
  a Livewire component or Filament resource.
- **[stripe-testing.md](stripe-testing.md)** — the payment suite's own
  version of that, and the honest record of what is *not* covered and why.
  Read before trusting the payment path.
- **[browser-testing.md](browser-testing.md)** — what only a real browser
  can prove (§37 #19, responsive), with the same honest list of gaps: one
  browser, no real devices, no text zoom.
- **[accessibility-testing.md](accessibility-testing.md)** — `axe-core`
  against the storefront critical path: a stray nested `<main>` landmark
  found and fixed live, a systemic colour-contrast failure found and
  recorded (not yet fixed — it is a real design decision, not a one-line
  patch). Screen-reader and keyboard-only navigation are still open.
- **[chaos-testing.md](chaos-testing.md)** — four deliberately induced
  failures (Stripe unreachable, a real MySQL connection killed
  mid-transaction, mail service down, a Stripe order abandoned
  mid-connection): an uncaught-500 gap proven live, a rollback guarantee
  proven against a genuine network-level connection loss, a queue-retry
  mechanism confirmed correct but with no recovery path for a
  permanently-failed job, and the abandoned-checkout sweep proven
  end-to-end rather than only at its own two already-tested halves.
- **[dependency-currency.md](dependency-currency.md)** — `composer
  audit`/`npm audit` results and outdated-package currency, dated and
  separate from the load-test and application-scan pages since it changes
  on upstream's release cadence, not this codebase's own.
- **[performance-testing.md](performance-testing.md)** — dated findings
  from load tests against a stress-scale catalogue: a real N+1, two
  missing indexes, catalogue search's full-table-scan cost, and — at the
  largest run, 100,000 products with 2.5M variations and 5M images through
  `DeepCatalogueStressSeeder` — the plainly-stated number that a single
  product page still loads in ~100ms at that scale, 18× faster than the
  catalogue list page the other findings are about. Every claim carries
  its query log and `EXPLAIN` output. See
  `../../how-to/measure-performance-under-load.md` for the reproducible
  procedure.
- **[security-testing/](security-testing/)** — what a security pass
  probed, what held, and what it could not reach. Read before trusting any
  claim that an authorization path is safe. See its own
  [index](security-testing.md).
- **[security-tooling.md](security-tooling.md)** — the inventory half of
  security testing: which scanners are configured how, the exact surface
  they reached, and the measured numbers behind every coverage claim.
- **[scanner-tooling/](scanner-tooling/)** — that tooling's actual inputs
  and outputs: `zap-auth.yaml` (the reusable authenticated-scan plan) and
  `reports/` (the dated, never-edited-after-the-fact output of each run).
- **[tested-inputs.md](tested-inputs.md)** and **[coverage.md](coverage.md)**
  — the input-fuzzing index and the PCOV coverage numbers.

## [ui-testing/](ui-testing/)

The interactive click-through, one file per pass rather than one growing
file: `phase-1-storefront-clickthrough.md` (every storefront page driven
live), `phase-2-admin-created-data.md` (broken catalogue data created
through real Actions, and what the customer's browser does with it),
`phase-3-error-leak-sweep.md` (what an unhandled failure actually shows an
anonymous visitor), and `phase-4-admin-panel-clickthrough.md` (the
role-denial matrix inside the panel). Screenshots live in
`../../assets/ui-testing/`, not loose in `assets/`.

## [security-testing/](security-testing/)

The dated findings record, `SEC-001` through `SEC-017`, split by
investigation arc rather than one file per finding. See its
[method-and-summary.md](security-testing/method-and-summary.md) for the
full index.
