# ADR-0012: Laravel Boost — adopted, generic guidance audited against this project's ADRs

Status: Accepted
Date: 2026-08-23 · Deciders: Stefan Marinkov

## Context

`laravel/boost` (dev-only) provides an MCP server — application/schema
inspection, DB query, log tail, and semantic search over ~17,000 pieces of
Laravel/Filament/Pest documentation — plus a bundle of AI-agent guideline
and skill files meant to steer code generation toward Laravel-ecosystem
best practice.

That bundle is written for a generic Laravel app. This project has ten
prior ADRs making specific, reasoned architectural choices, several of
which a generic best-practices bundle could plausibly contradict without
either side noticing. Installing it uncritically — accepting whatever it
generates as the new instruction set — would risk quietly overriding
decisions this project already made for stated reasons. The alternative,
not installing it, gives up a real capability (schema/log inspection,
Laravel docs search) over a risk that is addressable by reading what it
actually says before trusting it.

## Decision

### Install shape: generated files are disposable, one file is not

`php artisan boost:install` writes into `online-store/` (its `base_path()`,
one level below repo root) rather than colliding with the hand-written,
root-level `CLAUDE.md` this project already had. Four files it produces:

| File | Tracked? | Why |
|---|---|---|
| `online-store/.ai/guidelines/project-conventions.md` | **Yes** | Hand-written. The actual decision this ADR is about |
| `online-store/CLAUDE.md` | No | Generated: this project's conventions (compiled from the file above) followed by Boost's bundled guidance |
| `boost.json` | No | Generated: which agent/skills were selected |
| `.claude/skills/` | No | Generated: skill content, copied from the installed package |
| `.mcp.json` (repo root) | **Yes** | Hand-written — see below |

Boost's own docs suggest gitignoring exactly the generated set and say so
in the install output; this project already applies the identical split to
Blueprint's scaffolding (ADR-0001) and to `RaceWorker`'s spawned temp files
(now gone entirely, `explanation/concurrency-and-locking.md`) — source is
versioned, the artifact rebuilds from it. A teammate running
`boost:install` themselves regenerates their own local copy from the same
tracked source; nothing shared can be silently overwritten, because the
only two tracked files are ordinary git files subject to ordinary review.

`.mcp.json` is hand-written rather than generated as-is because this
project's `vendor/` only exists inside the `app` container — a bare `php
artisan boost:mcp` fails on the host. It points through
`docker compose exec -T app` instead. Verified with a real MCP
`initialize` handshake piped through that exact command before trusting it.

### Where this project adapted to Boost's guidance

Two real, measured changes, not merely suggested:

- **`tests/Pest.php`: `RefreshDatabase` → `LazilyRefreshDatabase`** for the
  `Feature` suite. Identical isolation guarantee — `LazilyRefreshDatabase`
  `use`s `RefreshDatabase` internally and only defers migration until a
  test's first DB touch. Measured back to back on this suite: 683s → 617s,
  479/479 tests unchanged. Verified structurally, not just measured, that
  this does not weaken the `pest --parallel` setup
  (`how-to/run-the-tests.md`, "Running in parallel"): Laravel's per-worker
  database switching checks `class_uses_recursive()` for
  `RefreshDatabase::class`, which resolves through the `use` inside
  `LazilyRefreshDatabase` the same as a direct use would.
- **`routes/console.php`: `carts:expire` gained `->withoutOverlapping()`.**
  A real, if minor, gap the scheduling skill named — a stalled run should
  skip the next tick rather than queue a second one against the same table.

### Where Boost's guidance was overridden, and why

Recorded in `.ai/guidelines/project-conventions.md`'s own "Where Boost's
generic guidance does not apply here" section, which is emitted *before*
Boost's bundled guidance in the generated file, so it reads as this
project's opinion first rather than an appendix to Boost's.

1. **"Only create documentation files if explicitly requested."**
   Contradicts this project's whole documentation culture: an
   undocumented error gets a `troubleshooting.md` entry, a shipped Action
   gets an `actions.md`/`write-rules/` update, user-visible work gets a
   `CHANGELOG.md` entry — as part of the work, not as an extra a request
   has to separately ask for. What still needs asking first is a *new* doc
   file or a new ADR, since those change the structure this file itself
   routes through.
2. **"Always use constructor injection, avoid `app()`/`resolve()`."**
   Zero exceptions to `app(SomeAction::class)->handle(...)` exist anywhere
   in this codebase's 30+ Actions, including every Filament page composing
   them and the newly-added `RaceWorker::dispatchAction()`'s 13-arm
   `match`. Constructor injection is for a long-lived collaborator a class
   holds across its whole life; an Action here is resolved once, at the
   call site, often chosen at runtime from several candidates — there is
   no stable "this class always needs this one Action" relationship for a
   constructor to express. Constructor-injecting `RaceWorker` would mean
   instantiating 13 Actions on every invocation to use one. See "Full
   rewrite?" below — this was checked against the whole codebase, not
   asserted from one file.
3. **"Code payment gateways to an interface."** ADR-0001 decided the
   opposite, by name and with reasoning: abstract the courier (two real
   implementations, Econt and Speedy) behind `App\Contracts` plus Saloon
   connectors; do not abstract Stripe (one implementation, nothing to swap
   it for). The skill's own worked example is a `PaymentGateway` interface
   over a single `StripeGateway` — precisely the case ADR-0001 argues
   against, for the same reason: an interface over one implementation is
   speculative generality, not testability.

### `Concurrency::run()` does not replace `race:worker` — verified, not asserted

Laravel's `Concurrency` facade looked, on the surface, like it might replace
the hand-built race harness (`app/Console/Commands/RaceWorker.php`,
`tests/Concurrency/RaceHelper.php`) with a framework primitive. Read
`Illuminate\Concurrency\ProcessDriver::run()` before concluding either way:

```php
$results = $this->processFactory->pool(function (Pool $pool) use ($tasks, $command, $timeout) {
    foreach (Arr::wrap($tasks) as $key => $task) {
        $process = $pool->as($key)->path(base_path())->env([
            'LARAVEL_INVOKABLE_CLOSURE' => base64_encode(serialize(new SerializableClosure($task))),
        ])->command($command);
        // ...
    }
})->start()->wait();
```

Three concrete gaps against what a race test needs, not a vague "wrong
tool" judgment:

- **No barrier.** `pool->start()->wait()` starts every task and blocks
  until all finish. Nothing aligns *when* each task begins running its
  closure — no analog to `race:worker`'s `--start-at` wall-clock instant,
  which exists specifically because sequential-looking starts turn a race
  into 2 ordered calls that pass with and without the lock under test.
- **No rendezvous.** Nothing between "started" and "finished" — no hook
  two tasks of unequal internal length could use to align their *arrival*
  at the operation under test, which two of twelve race tests need on top
  of the barrier (`concurrency-and-locking.md`, "A cross-Action race needs
  a fourth thing").
- **No per-task environment.** The `env()` array above is hardcoded to
  carry only the serialized closure — there is no public surface to pass
  `DB_DATABASE=online_shop_test` per task. A task spawned this way boots
  `artisan invoke-serialized-closure` against `.env` fresh, the same
  "silently races the *dev* database" failure `raceWorkerEnvironment()`
  exists to prevent, with no way to fix it from the caller's side.

Genuinely useful for its actual purpose — independent, unordered parallel
work (`Concurrency::run([fn () => User::count(), fn () => Order::count()])`)
— and not reached for here because that purpose is a different problem
than what `tests/Concurrency/` proves.

### Full rewrite to constructor injection? No — checked, not assumed

Grepped every `App\Actions\*` call site before answering: zero uses
constructor-injects another Action. All resolve via `app(X::class)` at the
point of use — Filament pages picking which Action a header button calls,
tests, and now `RaceWorker`'s 13-way dispatch. Rewriting this would touch
every caller for a purely mechanical, zero-behavior-change reason, against
the pattern ADR-0007 already established on purpose.

Boost's own `infer-conventions` skill states the more general principle
that applies here, worth quoting since it argues against Boost's *own*
generic advice in this specific case: *"Consistency first. The codebase's
majority style is the convention... The test for every candidate: without
this rule, would the next agent plausibly write it differently? Only 'yes'
earns a rule."* Applied honestly to this codebase, an agent reading 30+
existing Actions would reach for `app(X::class)` again without being told
to — so constructor injection fails infer-conventions' own bar for a rule
worth recording, let alone a rewrite worth doing.

### Identified, not acted on: `Model::preventLazyLoading()`

`db-performance.md` recommends
`Model::preventLazyLoading(! app()->isProduction())` in
`AppServiceProvider::boot()` — throws instead of silently N+1-querying in
dev/test. `AppServiceProvider` has no Eloquent strictness guard configured
at all today. Real gap, genuinely worth having eventually — not added
here: turning it on requires auditing every Filament resource's and
relation manager's eager-loading first, since Filament's own internals
lazy-load relationships in places this session did not verify one by one,
and a `LazyLoadingViolationException` thrown from inside a vendor package's
render path is a worse failure mode than the N+1 it would have replaced.
Matches this project's own "vertical slice" discipline: this is its own
slice, not a drive-by addition to a Boost-adoption ADR.

## Consequences

+ Nine MCP tools (schema, query, logs, docs search) available without
  guessing Filament v4 or Pest 4 API from memory.
+ Two real, measured improvements the audit surfaced that would otherwise
  not have been looked for: the `LazilyRefreshDatabase` swap and
  `carts:expire`'s `withoutOverlapping()`.
+ The override list is itself durable documentation: the next person who
  wonders "why doesn't this codebase constructor-inject Actions" or "why
  is Stripe not behind an interface" has an answer that predates Boost and
  one instance of Boost's own advice pointing the other way, recorded
  together.
+ `docs/reference/console-commands.md` exists because writing this ADR
  required first knowing what every custom command actually did — a gap
  that existed independent of Boost and got closed as a side effect.

− `laravel/boost` registers a live route, `POST _boost/browser-logs`,
  unconditionally, and its own master switch defaults to **on**
  (`'enabled' => env('BOOST_ENABLED', true)`). The only thing keeping it
  out of production is being a `require-dev` package a production install
  should omit — nothing in this repo currently documents or enforces
  `composer install --no-dev` for the Forge deploy, which predates this
  ADR but is newly relevant because of it.
  `reference/tech-stack.md` records this; worth confirming before the
  first real deployment.
− Generated `online-store/CLAUDE.md` is a second file a new contributor
  could mistake for the router, despite root `CLAUDE.md` now saying
  explicitly not to treat it that way. The gitignore comment and the
  cross-reference are the mitigation; neither stops someone from opening
  and editing the wrong file once before learning better.
− `.ai/guidelines/project-conventions.md` is now a second copy of a
  meaningful fraction of `CLAUDE.md`'s content, condensed. Genuine
  duplication risk: a rule changed in one and not the other silently
  diverges. No automated check enforces agreement between them today.

## Alternatives rejected

- **Don't install Boost.** Loses the MCP tools and the documentation
  search for no offsetting benefit — the actual risk (generic guidance
  contradicting a specific ADR) is addressable by reading what it
  generates before trusting it, which this ADR is the record of having
  done, not a reason to avoid the tool.
- **Accept Boost's generated `CLAUDE.md` as the new instruction set,
  uncritically.** Would have silently reopened the payment-gateway
  interface question ADR-0001 already closed, among others. Rejected by
  the audit this ADR records.
- **Run `infer-conventions` to auto-generate `.ai/rules/`.** Boost's own
  skill for detecting and recording this project's actual conventions.
  Not run: this ADR and `.ai/guidelines/project-conventions.md` already
  cover the same ground by hand, and running an automated sweep over
  conventions already deliberately written out risks producing a second,
  possibly-drifting record of the same decisions rather than one. Revisit
  if the hand-written guidance stops being kept current.
