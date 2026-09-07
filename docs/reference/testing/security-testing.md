# Security testing — what was probed, what held, what did not

Another page in the family `stripe-testing.md` and `browser-testing.md`
belong to: an honest record of a security pass, including what it could not
reach. `explanation/security-model.md` states the model this checks against;
this page is the result of checking.

**`how-to/pentest-the-system.md` is the procedure that produces this page** —
the tools, the scan commands, the model and effort each phase needs, and the
traps that turn a pass into a false all-clear. Read it before running the
next pass; read this one to see what the last pass found.

A pass here is a claim about the code as read on one date, plus whatever
runtime probing the environment allowed that day. It is not a claim that the
application is secure.

This page used to be one 1500-line file. It grew past a single sitting —
thirteen numbered findings plus the surrounding sections — so it is now
split under [`security-testing/`](security-testing/), one file per
investigation arc, respecting the numbering rather than cutting across it.
This page is the index.

## Index

- **[method-and-summary.md](security-testing/method-and-summary.md)** — how
  this pass was run, its limits, the dependency-scan table, and what the
  findings below add up to.
- **[sec-001-to-004.md](security-testing/sec-001-to-004.md)** — the two
  exploitable IDORs (`ArticleDetails`, `CheckoutPage`), the unreachable
  `league/commonmark` advisory, and the first OWASP ZAP baseline scan.
- **[sec-005-to-007.md](security-testing/sec-005-to-007.md)** — the full
  active scan, the CSP/CORP fix (and the wildcard caught mid-fix), the
  first authenticated scan, and the two re-runs that closed its
  concurrency race. Also carries the `#[Locked]`-absent-project-wide
  pattern note that ties SEC-001 and SEC-002 together.
- **[sec-008-to-010.md](security-testing/sec-008-to-010.md)** — the latent
  role-permission self-escalation, the CSP that blocked Stripe's own
  checkout, and the four unthrottled public forms.
- **[sec-011-to-013.md](security-testing/sec-011-to-013.md)** — the
  tracking-lookup leniency probe (dismissed), the Stripe client secret
  leaking into the access log, and the unset `SESSION_SECURE_COOKIE`.
- **[what-held.md](security-testing/what-held.md)** — the role-based access
  control sweep across all 19 admin resources, everything else checked and
  found correct, and the later 2026-09-06 ZAP runs (`/orders/track` full
  scan, the seven informational pages).
- **[gaps-and-not-covered.md](security-testing/gaps-and-not-covered.md)** —
  hardening gaps that are not vulnerabilities today, and what no pass so
  far has reached.

## Finding a specific `SEC-NNN`

```bash
grep -rn "SEC-00" docs/reference/testing/security-testing/
```
