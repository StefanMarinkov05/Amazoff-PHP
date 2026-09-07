# Testing

Every "what did testing prove" document, grouped apart from the system-fact
files one level up (`actions.md`, `permissions.md`, …) since these change at
a different rate and for different reasons — a test result is a claim about
one date, not a standing fact about the system.

## Top-level pages

- **[ui-tests.md](ui-tests.md)** — every storefront and admin-panel UI test
  and what it proves, grouped by component/resource. Check before changing
  a Livewire component or Filament resource.
- **[stripe-testing.md](stripe-testing.md)** — the payment suite's own
  version of that, and the honest record of what is *not* covered and why.
  Read before trusting the payment path.
- **[browser-testing.md](browser-testing.md)** — what only a real browser
  can prove (§37 #19, responsive), with the same honest list of gaps: one
  browser, no real devices, no text zoom.
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

The dated findings record, `SEC-001` through `SEC-013`, split by
investigation arc rather than one file per finding. See its
[method-and-summary.md](security-testing/method-and-summary.md) for the
full index.
