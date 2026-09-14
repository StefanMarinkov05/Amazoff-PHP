# Deferred and reactive work — scheduler, jobs, events, listeners

Every mechanism in this codebase for running code somewhere other than
directly inside the request/response cycle that triggered it, in one place.
Facts only — what exists and what invokes it. The *why* for each piece
lives in `explanation/queues-and-jobs.md` (queues and Jobs) and
`explanation/transactional-email.md` (the mailables); this page is the
inventory the other two draw examples from.

These four mechanisms answer different questions, and none of them
substitutes for another:

| Mechanism | Answers | Runs | Repeats? |
|---|---|---|---|
| **Scheduler** (`Schedule::command()`) | "Call this on a fixed cadence" | `schedule:work`, one process | Yes, forever, on the clock |
| **Queue / Job** (`ShouldQueue`) | "Run this once, off the request, later" | `queue:work`, a separate worker process | No — one dispatch, one execution (retried on failure, not repeated on a timer) |
| **Event + Listener** | "Who reacts when X happens, and what do they do" | In-process, synchronously, wherever the event is dispatched — unless a listener itself queues something | No — fires once per dispatch, same as a normal function call |
| **Console command** | The unit of work the scheduler (or a human) actually invokes | On demand, or via the row above | N/A — it's what schedule/manual both call |

The three commonly conflated: a **scheduled command** runs on a timer and
does not, by itself, defer anything — it is `Schedule::command()`'s
*target*. A **Job** defers one unit of work triggered by something
happening, once, and does not repeat on its own. An **event/listener** is
a within-process reaction, and only becomes "deferred" if the listener
itself dispatches a Job. `SyncShipmentTracking` (below) is the one case in
this codebase where a scheduled command's whole purpose is to *create* Jobs
— the schedule fires every 5 minutes, and each firing queues N Jobs, one per
shipment, each of which runs once.

## Scheduler — `routes/console.php`

Laravel 11+ convention: `Schedule::command()` calls live in `routes/console.php`,
not `app/Console/Kernel.php::schedule()`. Every entry:

| Command | Cadence | What it does |
|---|---|---|
| `carts:expire` | daily | Deletes carts past `expires_at` |
| `orders:purge-anonymised` | weekly | Deletes GDPR-anonymised orders past the accounting-retention window (ADR-0019) |
| `newsletter:purge-unconfirmed` | daily | Deletes newsletter rows never confirmed within 30 days (ePrivacy Art. 13, ADR-0019) |
| `products:snapshot-prices` | daily | Records every product's effective price for the Omnibus 30-day prior-price display (ADR-0021) |
| `orders:expire-unpaid` | every minute | Cancels card orders abandoned at the Stripe step, releases stock (ADR-0022) |
| `shipments:sync-tracking` | every 5 minutes | Queues one `SyncShipmentTracking` Job per shipment still `Shipped`/`InTransit` with a tracking number — slice 8 |

Full detail per command, including refusal conditions and exact write
targets: `console-commands.md`.

**Nothing runs the scheduler locally.** `docker-compose.yml` has no
`schedule:run`/`schedule:work` process — a scheduled command only fires
locally via a manual `php artisan schedule:run` or `schedule:work`.

**On Railway, the scheduler service's existence is the load-bearing fact.**
Every row in the table above is inert on the beta until a dedicated
`scheduler` service runs `php artisan schedule:work` — see
`how-to/deploy-and-host.md`'s "Worker + scheduler" section for current
status. Until it exists, `railway ssh -- php artisan <command>` is the only
way any of these run on the live box.

## Queue / Jobs — `app/Jobs/`

| Job | Dispatched by | Runs |
|---|---|---|
| `App\Jobs\SyncShipmentTracking` | `app/Console/Commands/SyncShipmentsTracking.php`, once per syncable shipment | The **worker** (`queue:work`) |

Only one Job class exists. It wraps `App\Actions\Shipment\SyncShipmentTracking`
for queued execution — see `explanation/queues-and-jobs.md` for the
mechanism (the `jobs`/`failed_jobs` tables, retry/backoff, what happens on
a permanent failure) and its "How to add a real Job class" recipe, which
this Job follows exactly.

**Queued Mailables are not Jobs**, though they use the same `jobs` table and
the same worker. `App\Mail\*` classes implementing `ShouldQueue` are listed
in `explanation/transactional-email.md`'s "Current mailables" table, not
here — that page is the authoritative list for mail specifically.

**On Railway, the worker service's existence is the load-bearing fact**,
identical to the scheduler above: nothing in the `jobs` table — a queued
mailable or a queued `SyncShipmentTracking` — is ever processed until a
dedicated `worker` service runs `php artisan queue:work`. See
`how-to/deploy-and-host.md`.

## Events and Listeners — `app/Events/`, `app/Listeners/`

| Event | Dispatched by | Listener | Does |
|---|---|---|---|
| `App\Events\OrderStatusChanged` | `TransitionOrderStatus`, after every status write commits (`ShouldDispatchAfterCommit`) | `App\Listeners\SendOrderPlacedConfirmation` | Queues the CRD Art. 8(7) confirmation mailable for a **card** order once the event's `to` reaches `Paid`. Does nothing for cash-on-delivery, whose confirmation `CheckoutPage::placeOrder` queues directly at placement instead |

Only one event/listener pair exists in the whole codebase — confirmed by
directly listing `app/Events/` and `app/Listeners/`, not inferred.
`OrderStatusChanged` carries the order, the `from`/`to` status, and the
acting user; bound to its listener via Laravel's auto-discovery (no manual
`EventServiceProvider` entry needed in this Laravel version — confirmed
live via `artisan event:list`).

**Why this event is `ShouldDispatchAfterCommit`, and what that has to do
with Jobs**: `TransitionOrderStatus` can be called from inside a caller's
own transaction (nested via savepoints, `add-an-action.md` §5). An event
dispatched mid-transaction would be delivered even if that transaction
later rolled back — a customer notified about a status change that never
happened. `ShouldDispatchAfterCommit` defers *dispatch itself* until the
outermost transaction commits, which is a different mechanism from
queueing a Job: the event still fires synchronously once dispatched, it is
only the *dispatch* that waits. `SyncShipmentTracking` (the Action) never
needs this, because it dispatches nothing — its Job counterpart is created
by a console command with no surrounding transaction at all.

## Where each mechanism's own detail lives

- **Scheduler cadence and refusal conditions per command** —
  `console-commands.md`.
- **Queue mechanism, retry/failure handling, how to add a Job** —
  `explanation/queues-and-jobs.md`.
- **Mailables specifically — what each sends, to whom, under what
  regulation** — `explanation/transactional-email.md`.
- **`TransitionShipmentStatus`/`SyncShipmentTracking`'s own concurrency
  story** (the scheduled sync racing a manual panel click) —
  `reference/write-rules/concurrency.md`.
