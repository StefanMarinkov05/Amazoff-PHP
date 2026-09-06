# Security scan artifacts

Reusable inputs and dated outputs, kept apart because they change at
different rates and for different reasons.

- **`zap-auth.yaml`** — the authenticated OWASP ZAP Automation Framework
  plan. This is a *template*, not a record: it has no real session cookie
  in it, and the same file is re-run for every future authenticated pass
  after substituting a fresh login. The comment block at the top of the
  file is load-bearing — it names the one silent misconfiguration that cost
  the most time to find (a concurrency parameter accepted by one job and
  silently ignored by another) and the launch command that avoids it.
- **`reports/`** — the dated `.md` output of each run this plan produced,
  never edited after the fact. One file per pass, named
  `zap-authenticated-<date>-run<n>.md`; `run<n>` distinguishes same-day
  reruns (a config fix followed immediately by a corrected re-run, say).

This mirrors `docs/reference/testing/ui-testing/` — reusable procedure separate from
one dated pass's record — for the same reason: a reader asking "how do I run
this" and a reader asking "what did the last run find" want different files.

## Where the findings themselves live

A ZAP report is raw material, not a finding. Every alert in `reports/` has
been triaged — real, false positive with a stated reason, or already known
— and the ones worth keeping are written up in
`docs/reference/testing/security-testing.md` in the project's own numbered
(`SEC-NNN`) finding format. Read that file for "what is actually wrong and
what was done about it"; read a report here only to see the raw scan output
a given entry's reasoning was built from.

## Running it

`docs/how-to/pentest-the-system.md` has the full procedure this plan is one
step of — the authentication handshake, every trap the comment block in
`zap-auth.yaml` summarizes, and how to triage what comes back.
`~/.claude/skills/website-testing/references/security-tooling.md` has the
same material generalised for use on a different project.
