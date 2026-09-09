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
| `orders:purge-anonymised` | Deletes GDPR-anonymised orders past `config('gdpr.order_retention_years')` — the accounting-retention window. Disabled (says so, does nothing) when the config value is `null` (ADR-0019) | The scheduler, weekly |
| `race:worker` | Runs 1 Action as a participant in a two-process race | `tests/Concurrency/*`, never a human |
| `fixtures:validate` | Checks a catalogue fixture set before any row is written | A human, before `DemoSeeder` |
| `fixtures:validate-articles` | Same, for the article fixture set | A human, before `DemoArticleSeeder` |
| `demo:fetch-images` | Downloads real Pexels photos for `product_images` rows still pointing at a placeholder path that does not exist on disk. One search per product; the whole catalogue finishes in a single run (25,000 requests/hour free tier), still resumable if interrupted. `how-to/seed-the-database.md`, "Product images" | A human, once, after the catalogue is seeded — already run; the 182 result files are committed |
| `demo:seed` | Loads the whole demo dataset in dependency order (`Demo\DemoDatabaseSeeder`); `--fresh` resets the database first. `how-to/seed-the-database.md`, "Local, the full demo" | A human, whenever a demo database is wanted |
| `demo:stripe-payments` | Opens real Stripe **test** PaymentIntents against already-seeded orders, through `CreateStripeIntent`, for end-to-end payment simulation. Refuses a non-`sk_test_` key. Creates but never confirms — confirmation and the webhook are what an end-to-end run exercises. `how-to/seed-the-database.md`, "Real Stripe intents" | A human, opt-in, after `demo:seed` |
| `demo:fetch-article-images` | The article sibling of `demo:fetch-images` — downloads a real Pexels photo for every article whose `main_image_path` is empty or missing on disk, and writes the path onto the row. `components/journal/cover.blade.php` prefers it once it exists; falls back to generated art until then. `how-to/seed-the-database.md`, "Article images" | A human, once, after the article fixtures are loaded |
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

## `orders:purge-anonymised`

```bash
docker compose exec app php artisan orders:purge-anonymised
```

Scheduled `->weekly()`. A GDPR-anonymised order still exists because it is
an invoice under Bulgarian accounting law; once that retention window
expires nothing keeps it, so it is deleted outright (ADR-0019). The window
is `config('gdpr.order_retention_years')`, an `.env` value
(`GDPR_ORDER_RETENTION_YEARS`, default 11): the exact figure is a matter of
BG accounting law and a human sets it before go-live. Left `null`, the
command prints "disabled" and deletes nothing rather than guessing.

The command is a caller; `App\Actions\Gdpr\PurgeAnonymisedOrders` is the
rule. Weekly rather than daily because the window is measured in years — a
few days' lag between expiry and deletion is immaterial, and a daily run
would almost always find nothing.

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

## `demo:seed`

The composed path through `database/seeders/Demo/DemoDatabaseSeeder`, which
is where the run-order constraints live next to the calls they constrain.
Five of them are load-bearing — customers before the state that hangs off
them, addresses and coupons before orders, orders before reviews (enforced by
`CreateProductReview` itself, not merely by seed order), reviews before the
showcase labels, and content reference rows before articles.

`--fresh` composes `migrate:fresh --seed` with the load, because the two-step
form is easy to half-run and a demo set loaded onto a previous run's orders
is the state the command exists to stop being normal. It aborts rather than
seeding if the reset fails.

Refuses to run in production before touching anything. Every individual
`Demo*` seeder already carries that guard, but a command that resets the
database should not rely on a guard living one call deeper.

Deliberately not wired into `migrate:fresh --seed`: CI wants the smallest
fixture that exercises the code, and this set is neither small nor fast.

## `demo:stripe-payments`

Opt-in, network-calling, and not part of any seeder — the same shape as
`demo:fetch-images`, and for the same reason: seeding is offline and
deterministic per ADR-0003, so anything needing an API key nobody else's
environment has stays outside the chain.

| Option | Meaning |
|---|---|
| `--limit=` | How many payments to open an intent for (default 10) |
| `--dry-run` | Report what would be done without calling Stripe |

Eligible payments are `method = stripe`, status in (`pending`, `processing`),
and no intent yet — mirroring `CreateStripeIntent`'s own guard rather than
trusting it to refuse, so a run is not a wall of caught refusals.

It calls the Action rather than `StripeClient`, so the amount still comes off
the payment row, the row is still locked and re-read, and the `metadata` the
webhook matches on is still set by the application. A second intent-creation
path would prove nothing about the first.

**Re-seeding within 24 hours produces skips, not failures.** The Action keys
idempotency on `payment-intent-{id}`; payment ids restart at 1 on every
`migrate:fresh` while Stripe remembers a key account-wide for 24 hours, so
low ids collide with a previous seed's payments at different amounts. The
command detects `idempotency_key_in_use` and reports it as a skip. That key
is a double-charge defence — do not weaken it to make a demo tidier.

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
