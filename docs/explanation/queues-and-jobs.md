# Queues and jobs

How deferred work runs in this application. `transactional-email.md` covers
the mailables themselves (what each one sends, when, and under what
regulation); this page is the mechanism underneath, which that page
deliberately doesn't repeat.

## `app/Jobs/` — one real Job class, added 2026-09-14

Until slice 8's tracking sync, every piece of deferred work in this codebase
was a **queued Mailable** (`App\Mail\*` implementing `ShouldQueue`), not a
dedicated Job class — `app/Jobs/` did not exist, and this page's "how to add
a Job" recipe below was hypothetical. `App\Jobs\SyncShipmentTracking` is now
the first real one: it wraps `App\Actions\Shipment\SyncShipmentTracking`
(the Action — business logic) for scheduled, queued execution, dispatched
once per shipment by `app/Console/Commands/SyncShipmentsTracking.php`
(`shipments:sync-tracking`, registered in `routes/console.php`). Read it
directly alongside this page's recipe — it follows every rule below, for
real, rather than as an illustration.

The four mailables that queue, and what queues them, are listed in
`transactional-email.md`'s "Current mailables" table. `SyncShipmentTracking`
is currently the only non-mail entry in the `jobs` table.

## The mechanism

**`QUEUE_CONNECTION=database`.** A `ShouldQueue` mailable's `->queue()` call
serializes itself into a row on the `jobs` table
(`0001_01_01_000002_create_jobs_table.php` — the framework's own default
migration, unmodified) rather than sending immediately. The web request that
queued it returns its normal response the instant the row is written,
regardless of whether the mail ever actually sends — this is what keeps a
slow or unreachable mail host from holding checkout, or any other request,
open.

**A separate worker process drains the table.** `php artisan queue:work`
polls `jobs`, claims a row, runs it, and deletes it on success. This is a
long-running process, not a request-triggered one — nothing about a queued
job runs unless something is actively running `queue:work` against the same
database. Concretely:

| Environment | What runs `queue:work` |
|---|---|
| Local (Docker) | The `queue` service in `docker-compose.yml` — same image as `app`, `restart: unless-stopped`, `--max-time=3600` recycles it hourly so a code change is picked up without a manual restart |
| Test | Nothing — `QUEUE_CONNECTION=sync` in the test environment runs a queued job inline, in the same process, the instant it's dispatched. `Mail::fake()`/`Mail::assertQueued()` see it synchronously either way |
| Railway (beta) | **Nothing, as of this writing.** A dedicated `worker` service running `queue:work` is planned (`how-to/deploy-and-host.md`) but not yet created — see that page's "Known accepted trades" for current status. Until it exists, a queued mailable sits in `jobs` forever and never sends |
| Forge (never provisioned) | Would need a supervisor-managed `queue:work` process, per ADR-0001 |

**`--tries=3 --max-time=3600 --sleep=1`** is the exact command in both
`docker-compose.yml` and the documented Railway worker command. `--tries=3`
is tracked on the job row itself (`attempts` column), not in the worker
process's memory — confirmed live in `reference/testing/chaos-testing.md`'s
Finding 3: a worker process that crashes and restarts between attempts still
counts correctly toward the limit, because the count survives on disk, not
in the process that crashed.

**After exactly 3 failed attempts, a job moves to `failed_jobs`** with its
full exception recorded (`failed_jobs.exception`) — and stops there. No
scheduled command, listener, or operator alert reads that table today. A
mailable that failed during a real but transient outage (not just a stopped
local `mailpit`) is silently lost forever unless a human runs
`php artisan queue:retry` by hand after noticing. This is a recorded, not
fixed, gap — `reference/testing/chaos-testing.md`'s Finding 3 has the full
account of how it was found and verified, including the worker-process-exits-
on-Mailer-failure behaviour that makes the crash-loop symptom look alarming
even though the retry count underneath it is correct.

## Retrying and inspecting

```bash
docker compose exec app php artisan queue:failed          # list failed jobs
docker compose exec app php artisan queue:retry <id>       # re-queue one
docker compose exec app php artisan queue:retry all        # re-queue every failed job
docker compose exec app php artisan queue:flush            # discard all failed jobs
docker compose exec app php artisan queue:restart          # signal workers to finish their current job, then exit
```

`queue:restart` matters after a code deploy: a long-running `queue:work`
process has the old code loaded in memory and keeps using it until it
exits. `--max-time` (already set locally and in the documented Railway
command) bounds how long that staleness can last; `queue:restart` ends it
immediately.

## Troubleshooting

**A `queue` container/service crash-loops.** Almost always the mail host
being unreachable (Symfony Mailer's transport exception exits the worker
process), not a bug in application code — see
`how-to/troubleshooting/infra-and-environment.md`'s "The `queue` container
crash-loops" entry before assuming otherwise. `restart: unless-stopped`
recovering it within a second is expected, correct behaviour, not a sign
something is broken.

**A queued mailable never seems to send.** Check whether anything is
actually running `queue:work` against the same database first — on Railway
specifically, the answer today is no (see the table above). `php artisan
queue:failed` next, to see whether it's stuck retrying or has already
exhausted its attempts.

## How to add a real Job class

A `ShouldQueue` Mailable is the right shape only while the deferred unit of
work *is* sending one email. The moment deferred work needs to do something
that isn't "render and send a Mailable" — call an external API, chain
several steps, retry with custom backoff logic distinct from mail's own, or
be dispatched from more than one place with different data each time — it
belongs in `app/Jobs/`, not bolted onto a Mailable class that was never
meant to carry it. `App\Jobs\SyncShipmentTracking` follows every step below
for real; read it alongside this recipe rather than the snippet, which is
now illustrative of the *shape*, not a stand-in for a class that doesn't
exist.

1. **Check whether it needs to be a Job at all.** If the work can run
   synchronously inside the request/response cycle in well under a second,
   with no external call that could hang, it may not need queueing. Every
   mailable queued in this codebase is queued specifically because it sends
   mail through a host that can be slow or down; `SyncShipmentTracking` is
   queued for the identical reason, with a courier's API standing in for a
   mail host.

2. **Generate it the ordinary way** — `make:job` is scaffolding, not the
   `make:model`/`make:migration` class this project avoids per
   `coding-conventions.md`'s "Where Boost's generic guidance does not apply
   here" (that exception is about Blueprint-generated models, not
   about one-off classes like a Job):
   ```bash
   docker compose exec app php artisan make:job YourJobName
   ```
   The scaffold Laravel actually generates uses
   `Illuminate\Foundation\Queue\Queueable` — a single trait bundling
   `Dispatchable`, `InteractsWithQueue`, `Queueable` (the bus one) and
   `SerializesModels` — not the four separate traits older Laravel
   examples show. `App\Jobs\SyncShipmentTracking` uses the modern form;
   match it rather than the four-trait shape if a tutorial or an LLM
   suggests otherwise.

3. **`handle()` is the entry point**, resolved by the queue worker via the
   container (so it can type-hint any dependency the same way an Action's
   `handle()` does), not called directly. `App\Jobs\SyncShipmentTracking`'s
   real shape:
   ```php
   final class SyncShipmentTracking implements ShouldBeUnique, ShouldQueue
   {
       use Queueable;

       public int $tries = 4;

       public function __construct(public readonly int $shipmentId) {}

       public function uniqueId(): string
       {
           return (string) $this->shipmentId;
       }

       public function backoff(): array
       {
           return [30, 120, 300];
       }

       public function handle(SyncShipmentTrackingAction $action): void
       {
           $shipment = Shipment::find($this->shipmentId);

           if ($shipment === null) {
               return;
           }

           $action->handle($shipment, null);
       }
   }
   ```
   Serialize an **id**, not the model — `SerializesModels` on a Mailable
   already follows this rule in this codebase (`transactional-email.md`),
   for the same reason: a job dispatched now and run minutes later must see
   the row's *current* state (an anonymisation, a cancellation, or here, the
   row being deleted entirely) when it finally runs, not a frozen copy of
   what was true at dispatch time. Guard the "resolved to nothing" case
   explicitly and return rather than throw — a deleted row by the time a
   worker gets to it is not a failure to retry.

4. **Business logic still belongs in an Action.** A Job's `handle()` should
   call an existing (or new) `App\Actions\*` class the same way a Filament
   resource or console command would, not reimplement the write inline —
   `add-an-action.md` is the recipe for that half. The Job is the delivery
   mechanism (when and how often it runs, what happens on failure); the
   Action is still where the invariant lives.
   `App\Actions\Shipment\SyncShipmentTracking` (the Action) and
   `App\Jobs\SyncShipmentTracking` (the Job) share a base class name in
   different namespaces on purpose — same convention
   `app/Console/Commands/ExpireUnpaidOrders.php` already uses for its
   Action of the same name, imported under an alias
   (`use ... as ExpireUnpaidOrdersAction`) where both are referenced in one
   file.

5. **Dispatch after the transaction commits**, never inside it — the same
   rule `transactional-email.md` states for mailables applies identically
   here, and for the identical reason: a job dispatched inside a
   transaction that then rolls back runs anyway, because the queue
   connection is separate from the database transaction. Either dispatch
   after `DB::transaction(...)` returns, or use `ShouldDispatchAfterCommit`
   if the dispatch site is nested inside a caller's own transaction and
   can't easily be moved outside it (the pattern
   `App\Events\OrderStatusChanged` already uses for the identical reason,
   per ADR-0007). `SyncShipmentTracking` dispatches from a console command
   with no transaction of its own at all
   (`app/Console/Commands/SyncShipmentsTracking.php`), which sidesteps the
   question entirely — worth checking whether a new Job's dispatch site
   has the same shape before reaching for either mechanism.

6. **Decide retry behavior explicitly** rather than inheriting the default,
   and check the arithmetic: `attempts() >= $tries` is what actually fails
   a job permanently (`Illuminate\Queue\Worker::markJobAsFailedIfWillExceedMaxAttempts()`),
   checked *before* the next backoff delay is consulted — so a `backoff()`
   array longer than `$tries - 1` has dead entries that never run.
   `SyncShipmentTracking` originally shipped with `$tries = 3` and a
   3-element `backoff()`, silently wasting the last delay; fixed to
   `$tries = 4` so all three widening delays (`[30, 120, 300]`) actually
   run. `$tries`/`backoff()` values should differ from the worker
   command's own blanket `--tries=3` for anything calling an external API
   (a courier gateway, a payment retry) where a fixed
   3-attempts-no-backoff shape may not fit the failure mode.
   `SyncShipmentTracking` does exactly this: `CourierUnavailableException`
   from the Action is what triggers a retry, since the Action deliberately
   never catches it itself.

7. **Test it the way `SyncShipmentTracking`'s own tests do**
   (`tests/Feature/Console/Commands/SyncShipmentsTrackingTest.php` for the
   dispatch side, `tests/Feature/Jobs/SyncShipmentTrackingTest.php` for the
   Job itself): `Queue::fake()` and
   `Queue::assertPushed(SyncShipmentTracking::class, fn ($job) => ...)` to
   prove the dispatch happened with the right data, and a separate test
   that constructs the Job directly and calls `->handle()` (with its
   dependencies mocked or real, per the Action's own test conventions) to
   prove the work itself is correct, including the "resolved to nothing"
   case from step 3. Testing discipline is otherwise identical to an
   Action's — prove something ours, not the framework's; see
   `coding-conventions.md`'s "Testing discipline" section.

8. **Update `reference/console-commands.md` and this page** if the Job
   introduces a new queue name (`->onQueue('couriers')`) or changes how
   `queue:work` needs to be invoked — a second named queue needs its own
   `--queue=` flag on the worker command locally and on Railway, or it is
   silently never processed by a worker only listening on `default`.
