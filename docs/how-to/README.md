# How-to

Short recipes for a specific job, in the Diátaxis sense: the reader already
knows the basics and wants the steps for one task, not the reasoning behind
it (that's [`explanation/`](../explanation/)) or the bare facts
([`reference/`](../reference/)).

## Setup and session start

- **[onboard-a-developer.md](onboard-a-developer.md)** — the reading and
  setup path for a new human developer joining the project: day-1 setup,
  the four documents to read before writing code, and a first task shaped
  small enough to finish.
- **[start-a-session.md](start-a-session.md)** — the reading order and
  priming prompt this project uses at the start of an agent session. Read
  this one first if you're new to working on this repo with an agent.
- **[set-up-claude-code.md](set-up-claude-code.md)** — the committed plugin
  list and hookify rules that mechanically enforce this project's own
  invariants.
- **[set-up-stripe.md](set-up-stripe.md)** — CLI/MCP account setup, and the
  identical-display-name trap that makes checking by account id mandatory.
- **[set-up-security-and-quality-tools.md](set-up-security-and-quality-tools.md)**
  — getting OWASP ZAP and Pcov actually running, distinct from
  `pentest-the-system.md`'s procedure for using them once they work.

## Building and changing things

- **[add-an-action.md](add-an-action.md)** — the shape a new Action takes.
- **[choose-a-model.md](choose-a-model.md)** — when to add a new Eloquent
  model vs. extend an existing one.
- **[regenerate-with-blueprint.md](regenerate-with-blueprint.md)** — the
  full procedure for re-running Blueprint without leaving stale generated
  code behind.
- **[edit-a-role.md](edit-a-role.md)** — changing what a Spatie role can do
  at runtime.
- **[write-a-storefront-page.md](write-a-storefront-page.md)** — every way
  UI is written here and what breaks each one; read before adding a Blade
  view.
- **[write-docs-and-comments.md](write-docs-and-comments.md)** — this
  project's documentation and comment conventions.

## Testing and verifying

- **[run-the-tests.md](run-the-tests.md)** — Pint/Larastan/Pest, parallel
  runs, and which suite needs which flags.
- **[test-for-input-crashes.md](test-for-input-crashes.md)** — fuzzing
  inputs for unhandled crashes.
- **[seed-the-database.md](seed-the-database.md)** — demo data, fixtures,
  and the Stripe payment seeder.
- **[emulate-a-device.md](emulate-a-device.md)** — isolated browser
  sessions and device profiles for responsive testing.
- **[pentest-the-system.md](pentest-the-system.md)** — the full security-pass
  procedure: which layer finds which bug class, the model/effort split it
  needs, and why downgrading the model for the reasoning half produces a
  false all-clear.
- **[use-ci.md](use-ci.md)** — how CI is sharded, and where a new test file
  goes.
- **[measure-performance-under-load.md](measure-performance-under-load.md)**
  — seed the catalogue at scale and measure real query counts and timing
  against it, rather than guessing from reading the query builder code.
  `reference/testing/performance-testing.md` is where a finding from this
  procedure gets recorded.

## When something breaks

- **[troubleshooting.md](troubleshooting.md)** — index into
  [`troubleshooting/`](troubleshooting/), split by area. Read *before*
  proposing a fix for any error — several of this project's errors look
  like ordinary bugs and are not.

## Deploying

- **[deploy-and-host.md](deploy-and-host.md)** — the checklist of settings
  that are correct in dev only by accident of dev's own environment,
  starting with `SESSION_SECURE_COOKIE`.

## Presenting

- **[run-a-customer-demo.md](run-a-customer-demo.md)** — a click-through
  script for a live audience: exact accounts, exact SKUs (including the
  13-item curated tour), and a fallback plan if something breaks live.
