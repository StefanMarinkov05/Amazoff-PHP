# Security testing — method, and what the findings add up to

Part of the [security-testing index](../security-testing.md). Another page
in the family `stripe-testing.md` and `browser-testing.md` belong to: an
honest record of a security pass, including what it could not reach.
`explanation/security-model.md` states the model this checks against; this
page is the result of checking.

**`how-to/pentest-the-system.md` is the procedure that produces this page** —
the tools, the scan commands, the model and effort each phase needs, and the
traps that turn a pass into a false all-clear. Read it before running the
next pass; read this one to see what the last pass found.

A pass here is a claim about the code as read on one date, plus whatever
runtime probing the environment allowed that day. It is not a claim that the
application is secure.

## Method, and its limits

Static review against the whole request path — routes, middleware,
Livewire components, policies, models, raw SQL, and the view layer — with
`CLAUDE.md`'s "Security rules that are ours" as the checklist, since those
are the rules the framework does not enforce on its own.

**Runtime probing was blocked** for most of 2026-09-03 by a host fault
(see `how-to/troubleshooting/infra-and-environment.md`, "Every container is
healthy and every request 504s").
That fault was repaired on 2026-09-03 (a reboot; see the troubleshooting
entry), and both code findings below were then **confirmed by live
exploitation** against the running app with a real browser driving Livewire
— not merely read from source. The reproduction for each is recorded with
it.

Dependency scanning did complete once the host was repaired.

| Scan | Result |
|---|---|
| `npm audit --omit=dev` | 0 vulnerabilities |
| `composer audit` | **4 advisories, 1 package** — see below |

## Findings

Seven entries. Two are exploitable IDOR/authorization bypasses in this
project's own code, both confirmed by live exploitation on 2026-09-03 and
both since fixed; both share one shape — a client-writable Livewire public
property that a query downstream trusts. The third is a dependency advisory
with no reachable path today. The fourth is an OWASP ZAP baseline scan
confirming the missing-headers gap mechanically and catching one item worth
investigating rather than accepting at face value. The fifth records a
**negative** result — a full active scan finding no injection vulnerability —
because a clean scan is worth nothing unless what it actually covered, and
what it could not see, is written down beside it. Each is written in
bug-bounty report form: **Finding → Reason → Reproduction → Fix → Logic for
future pentests.** The sixth records the headers added to close what the
fifth found still open, including a wildcard the scanner caught in the first
attempt at the fix. The seventh is the first *authenticated* scan — the run
that finally reached the admin panel — and the header gap only it could
find.

Severity uses CVSS-style qualitative bands (Critical / High / Medium / Low /
Info), rated for this application in its current state, not in the abstract.

Findings are grouped across files by the investigation arc they belong to,
not one file per finding — several (SEC-005 through SEC-007 especially) are
a single continuous scan-fix-rescan story where splitting at each `SEC-NNN`
boundary would separate a fix from the finding it closes:

- [sec-001-to-004.md](sec-001-to-004.md) — the two exploitable IDORs
  (`ArticleDetails`, `CheckoutPage`), the unreachable `league/commonmark`
  advisory, and the first OWASP ZAP baseline scan.
- [sec-005-to-007.md](sec-005-to-007.md) — the full active scan, the CSP/CORP
  fix (and the wildcard caught mid-fix), the first authenticated scan, and
  the two re-runs that closed its concurrency race.
- [sec-008-to-010.md](sec-008-to-010.md) — the latent role-permission
  self-escalation, the CSP that blocked Stripe's own checkout, and the four
  unthrottled public forms.
- [sec-011-to-013.md](sec-011-to-013.md) — the tracking-lookup leniency
  probe (dismissed), the Stripe client secret leaking into the access log,
  and the unset `SESSION_SECURE_COOKIE`.
- [what-held.md](what-held.md) — the role-based access control sweep and
  everything else checked and found correct, plus the later 2026-09-06 ZAP
  runs.
- [gaps-and-not-covered.md](gaps-and-not-covered.md) — hardening gaps that
  are not vulnerabilities today, and what no pass so far has reached.
