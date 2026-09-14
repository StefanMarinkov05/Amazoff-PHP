# How to set up the security and code-quality tools this project uses

Install and first-run steps for the tools that are either already baked into
this project's Docker image (Pcov) or run as an external container against
it (OWASP ZAP) — the setup half that `how-to/pentest-the-system.md` (the
*procedure*) and `reference/testing/security-tooling.md` (the *coverage
record*) both assume is already done. Neither of those files is the place
for "how do I get this running for the first time"; this one is.

Claude Code plugins (Playwright, Semgrep as a marketplace plugin, hookify)
are a separate concern — `set-up-claude-code.md` owns those, and
`misc/plugin-evaluation.md` (gitignored, not authoritative) has the
reasoning behind which ones this project uses and why. This doc covers the
tools that are not a Claude Code plugin: a container you run by hand, or a
PHP extension already baked into the image.

## OWASP ZAP

Runs as a standalone container on the app's Docker network — nothing to
install, `docker run` pulls the image on first use. The setup that matters
is not installation, it is **not repeating mistakes this project has
already made and documented.**

### Before running it for the first time

Read, in this order:

1. **`how-to/pentest-the-system.md`**, section 4 ("Automated DAST") — the
   full procedure and the actual command lines, including every trap found
   getting the authenticated scan working (Livewire auth, the `:80`
   port-normalisation bug, the `AuthenticateSession` concurrency race).
2. **`reference/testing/security-tooling.md`**, "Configuration, as actually
   used" — *why* each setting is what it is, and the measured evidence
   behind the authenticated scan's concurrency fix specifically.
3. **`how-to/troubleshooting/payments-and-security-tooling.md`**, "A ZAP full
   scan runs the machine out of memory" — what to do when a full scan does
   not complete, and — just as important — what *not* to conclude from
   that. An earlier version of that
   entry wrongly generalised a fix that was specific to the *authenticated*
   scan onto an unauthenticated one; the corrected entry has the accurate
   account.

These three files describe overlapping ground from different angles
(reference facts, full procedure, symptom-indexed fixes) — reading only one
is how a documented lesson gets missed and relearned. This has already
happened once on this project; it is why this section exists.

### First run — baseline scan

The cheapest way to confirm the setup works at all, against the storefront:

```bash
mkdir -p scratchpad
docker run --rm --network online_shop_teamb_default \
  -v "$(pwd)/scratchpad:/zap/wrk:rw" \
  ghcr.io/zaproxy/zaproxy:stable \
  zap-baseline.py -t http://webserver:80/catalogue -r zap-baseline-report.html
```

Note the target: `webserver:80`, the container's own network name, not
`localhost:8080` — the published host port is not reachable from inside
ZAP's container. Finishes in about a minute; the report lands in
`scratchpad/`, which is **not currently listed in `.gitignore`** — don't
assume a file placed there is protected from an accidental `git add -A`.

### A full (active) scan — the exact command lives in `pentest-the-system.md`

Genuinely attacks the app with real payloads — only ever against your own
dev instance. `how-to/pentest-the-system.md` section 4 has the actual
`zap-full-scan.py` invocation; don't duplicate it here, that file is the
one owner of "how to run this."

**One piece of new practice this setup doc adds on top of that command:
always pass a Docker-level memory cap.**

```bash
docker run --rm --network online_shop_teamb_default \
  -m 12g --memory-swap 12g \
  -v "$(pwd)/scratchpad:/zap/wrk:rw" \
  ghcr.io/zaproxy/zaproxy:stable \
  zap-full-scan.py -t http://webserver:80/<path> \
    -r report.html -x report.xml -m 5
```

`-m 12g --memory-swap 12g` is not optional — add it every time. It turns a
runaway scan into a contained kill of just that container instead of a
host-wide memory exhaustion, which has happened on this project's own
machine more than once, including on retries where the scan target was
smaller than the one that had previously succeeded without a cap.
**12 GB, not 10 GB — measured, not guessed.** `DomXssScanRule`'s headless-
browser launch (Firefox via geckodriver) needs real headroom beyond every
other active-scan rule; against `/orders/track` this OOM-killed the scan
at both 6 GB and 10 GB caps, confirmed via `docker inspect`'s `OOMKilled`
field, and only completed at 12 GB (`docker inspect`: `OOMKilled: false`,
report files written, ~1h33m wall-clock — see
`reference/testing/security-testing/what-held.md`'s "Other checks" table and
`how-to/troubleshooting/payments-and-security-tooling.md`'s ZAP entry for
the full evidence trail). Adjust down only on a machine known to have less
headroom, and expect the scan to fail to complete if you do — a smaller cap
is a real, reproducible failure mode here, not overcaution. Budget real
wall-clock time too, and expect it to run noticeably longer than a scan
that never engages `DomXssScanRule`'s browser dependency:
`pentest-the-system.md`'s cost table and
`how-to/troubleshooting/payments-and-security-tooling.md`'s memory entry
(both linked above) explain why `-m <minutes>` alone is not a full-run time
cap.

### An authenticated scan

Needs a real session cookie and an Automation Framework plan, not a bare
CLI flag — `pentest-the-system.md`'s "The authenticated scan" subsection is
the actual procedure and the one owner of the invocation, and
`reference/testing/scanner-tooling/zap-auth.yaml` is the checked-in, reusable
plan. Do not write a new authenticated-scan config from scratch; that file
already encodes four rounds of trial-and-error (session injection, the
port-normalisation bug, the concurrency race) that would otherwise repeat.

## Pcov (PHP code coverage)

Already installed in the app image (`docker/php/Dockerfile`) and enabled by
`docker/php/conf.d/pcov.ini` — nothing to set up locally beyond building the
image, which `docker compose up -d --build` already does. Confirm it is
active:

```bash
docker compose exec app php -m | grep -i pcov
```

Use it through Pest, not directly:

```bash
docker compose exec app ./vendor/bin/pest --coverage
docker compose exec app ./vendor/bin/pest --coverage --min=<threshold>
```

Pcov is chosen over Xdebug for this purpose specifically because it only
instruments coverage collection, not full step debugging — materially
faster on a suite this project's size, and the only coverage driver this
image installs. If a future need calls for interactive debugging (stepping
through a request, not measuring coverage), that is a separate Xdebug
setup, not a Pcov configuration change, and does not currently exist in
this image.

## Semgrep — two different things share this name here

`misc/plugin-evaluation.md`'s recommendation and `misc/todo.md`'s
"Installed plugins" section both refer to Semgrep, but as **two different
integration points** worth not conflating:

- **The Claude Code marketplace plugin** ("Semgrep Guardian") — passive,
  scans agent-written code automatically inside a session. Currently
  **disabled** in this repo's `.claude/settings.json`
  (`semgrep@claude-plugins-official: false`) because it wanted a cloud
  login `plugin-evaluation.md` was not prepared to grant without asking
  first. Enabling it is a `set-up-claude-code.md`-scoped decision, not a
  standalone-tool install.
- **The standalone `semgrep` CLI plus a project-specific `.semgrep.yml`** —
  not installed at all yet. This is the more valuable of the two for this
  project specifically: `misc/todo.md` names the exact rules worth writing
  (never `Order::findOrFail()` unscoped, never `$request->all()`, no raw
  `bc*` outside `App\Support\Money`, `{!! !!}` without a Purify call in
  scope) — mechanically enforcing invariants `CLAUDE.md` currently states
  and review currently catches by hand. Wiring this into CI is still
  outstanding; when it happens, it belongs in `how-to/use-ci.md` alongside
  the existing Pint/Larastan/Pest gate, and this section should gain the
  actual install command and `.semgrep.yml` location once one exists.

## What is deliberately not here

- **Playwright, chrome-devtools-mcp** — Claude Code plugins, not standalone
  tools; `set-up-claude-code.md` and `misc/plugin-evaluation.md` own them.
- **`composer audit` / `npm audit`** — no setup needed beyond the project's
  existing `composer install`/`npm install`; `pentest-the-system.md`
  section 6 has the invocation and triage discipline.
- **sqlmap, Metasploit, nikto** — deliberately not run on this project;
  `pentest-the-system.md`'s "Deliberately not run" list has the reasoning
  per tool, so it is not re-litigated here or re-proposed by a future
  session that has not read why.
