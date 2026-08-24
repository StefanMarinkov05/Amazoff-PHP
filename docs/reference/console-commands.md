# Console commands

Every custom Artisan command, what it does, and what invokes it. Laravel's
own commands (`migrate`, `queue:work`, `make:*`) are not listed — this is
only what this project added.

Commands live in `app/Console/Commands/`, auto-discovered: nothing registers
them by hand. Scheduling is in `routes/console.php` (Laravel 11+ replaced
`app/Console/Kernel.php::schedule()` with it).

| Command | Purpose | Invoked by |
|---|---|---|
| `carts:expire` | Deletes carts past `expires_at`, excluding any that already produced an order | The scheduler, daily |
| `race:worker` | Runs 1 Action as a participant in a two-process race | `tests/Concurrency/*`, never a human |
| `fixtures:validate` | Checks a catalogue fixture set before any row is written | A human, before `DemoSeeder` |
| `fixtures:validate-articles` | Same, for the article fixture set | A human, before `DemoArticleSeeder` |
| `demo:fetch-images` | Downloads real Unsplash photos for `product_images` rows still pointing at a placeholder path that does not exist on disk. Resumable — Unsplash's free tier is 50 requests/hour, one search per product, so a full catalogue needs several runs. `how-to/seed-the-database.md`, "Product images" | A human, once, after the catalogue is seeded |
| `inspire` | Laravel's stock placeholder, still present | — |

## What belongs in a command

A command is a *caller*, the same as a controller or a Filament page — it
validates its input, calls an Action, and reports. It is not where a rule
lives. `ExpireCarts` demonstrates the split: the command
(`app/Console/Commands/ExpireCarts.php`) parses nothing and prints a count;
the Action (`app/Actions/Cart/ExpireCarts.php`) decides what "expired" means
and which carts are exempt. Two classes with one name is deliberate — the
Action is the rule, the command is one way to reach it. ADR-0007's reasoning
for Filament resources applies unchanged.

The exception is `race:worker`, which is test infrastructure rather than a
domain caller, and dispatches to whichever Action a given race needs.

## `carts:expire`

```bash
docker compose exec app php artisan carts:expire
```

Scheduled `->daily()`. Housekeeping, not time-sensitive: nothing depends on
a cart disappearing promptly, and nothing reserves stock at cart-add time
(`reference/write-rules/cart.md`), so an expired cart holds nothing back.

**It currently has nothing to act on.** No code in `app/` writes
`carts.expires_at` — the column is nullable and cart creation does not set
it, because the TTL policy is not built. The intended shape, not yet
implemented: guest carts expire roughly a month after last touch; a
registered customer's cart does not expire at all, since the thing that
expires for a logged-in customer is the checkout stage rather than the cart.

## `race:worker`

Test-only, though registered unconditionally — it appears in `artisan list`
in production. It writes only through the same Actions and policies as any
other caller, so the exposure is a stray command name rather than a bypass.

```bash
php artisan race:worker reserve-stock --id=7 --arg=1 --start-at=1755900000.5
```

Not run by hand. `runRaceWorkers()` in `tests/Concurrency/RaceHelper.php`
builds the arguments and spawns one process per job.

| Option | Meaning |
|---|---|
| `action` | Which arm of `dispatchAction()` to run |
| `--id=*` | Model ids, in the order that arm documents |
| `--arg=*` | Scalars — a quantity, an `OrderStatus` value |
| `--start-at=` | `microtime(true)` instant every worker releases at |
| `--ready-file=` / `--peer-file=` | The cross-Action rendezvous; see below |

Actions available today: `reserve-stock`, `release-stock`, `add-to-cart`,
`merge-guest-cart`, `redeem-coupon`, `publish-product`, `remove-variation`,
`force-delete-variation`, `set-main-image`, `set-variation-images`,
`remove-image`, `delete-category`, `create-child-category`,
`transition-order-status`, `create-order`.

`set-variation-images` is the one arm whose `--id` list is variable-length:
the variation first, then the gallery in the order it should end up in.

`create-child-category` is plain Eloquent rather than an Action, matching
what Filament's default create does for a lookup table — the asymmetry
`DeleteProductCategoryConcurrencyTest` is about.

The worker prints `OK` or `FAILED:<exception class>` and nothing else. That
string *is* the protocol: assertions compare against it, so a command that
printed anything extra would break every race test at once.

`explanation/concurrency-and-locking.md`, "How this is tested", has why races
need two processes, a barrier, and sometimes a rendezvous at all.

## The scheduler does not run locally

Nothing invokes `schedule:run` in `docker-compose.yml` or the `app` image, so
a scheduled command never fires on its own during local development. Trigger
due tasks by hand:

```bash
docker compose exec app php artisan schedule:run    # runs what is due now
docker compose exec app php artisan schedule:work   # foreground loop
docker compose exec app php artisan schedule:list   # what is registered
```

Forge registers the cron entry in production (ADR-0001), so this affects
local dev only.
