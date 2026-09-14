# ADR-0023: Railway as the beta deploy target, in a container image this repo owns

Status: Accepted, the container-image half superseded by [ADR-0024](0024-railpack-over-custom-image.md)
Date: 2026-09-12 · Deciders: Stefan Marinkov

**[Superseded 2026-09-12]** The custom Dockerfile this ADR describes never
reached a working deploy — eleven attempts, documented in
`troubleshooting/infra-and-environment.md`'s "Railway deploy: eleven
failures, one Dockerfile" entry. ADR-0024 replaces the image with Railway's
own Railpack builder. Everything else here stands: Railway as the platform,
`APP_ENV=demo` and why, `trustProxies`, manual seeding via SSH rather than a
start-command step, and the accepted trades (ephemeral uploads, known
seeded-account passwords, no HSTS). Read this ADR for those; read ADR-0024
for how the image is actually built now.

Where the client-facing beta runs, and what that costs in settings nothing
in local development ever needed. Supersedes the hosting half of
[ADR-0001](0001-tech-stack-selection.md) — "Production runs on Forge, no
containers" — and nothing else in it.

## Context

The project needs a URL a client can open, to present a beta in front of
them. That is a different requirement from the one ADR-0001 answered.
ADR-0001 picked Forge while choosing a whole stack at once, and its
reasoning for Forge was never load-bearing: it is one line, in a section
about Docker, justified as "production runs on Forge, no containers" with no
argument attached. No Forge account exists, no host has ever been
provisioned, and `how-to/deploy-and-host.md` is explicit that it is a
pre-deploy checklist "never exercised against a real host."

So this is not a reversal of a tested decision. It is the first time the
question has actually been asked with a deadline behind it.

### What the beta is, precisely

**Demo data, no real customers, no real money.** The catalogue, the 140
orders, the customers and reviews all come from `demo:seed`. Stripe runs on
test keys. Nothing on the box is data anyone would miss, and that single
fact is what makes several otherwise-unacceptable trades acceptable below.
Each one is marked, so that when this box stops being a demo the list of
things to revisit is already written.

### Why Railway rather than Forge

Forge provisions PHP-FPM and nginx directly on a VPS that someone else has
to own and pay for; the repository has no access to one. Railway builds a
container from this repository and runs it, with a managed MySQL beside it,
on an account a single developer already controls. For a box whose purpose
is "be reachable on Thursday," the deciding factor is that nothing has to be
requested from anyone.

The cost of that choice is the rest of this document: a container platform
invalidates three assumptions the local stack and ADR-0001 both made.

## Decision

**Railway, from a production Dockerfile this repository owns, in four
services built from one image.**

Not Railway's own Laravel autodetection, which would serve the app through
php-fpm + Caddy with a build nothing here controls or can reproduce locally.
A Dockerfile in the repository means the deployed topology is reviewable,
diffable, and explicable — the same reason `docker-compose.yml` is committed
rather than described in prose.

### Three services, one image

| Service | Start command | Why separate |
|---|---|---|
| web | the image's entrypoint (nginx + php-fpm under supervisord) | — |
| worker | `php artisan queue:work --tries=3 --max-time=3600` | A worker that dies must restart without taking the site down |
| scheduler | `while true; do php artisan schedule:run; sleep 60; done` | `orders:expire-unpaid` runs every minute and must not be governed by the web container's health check |

Plus Railway's managed **MySQL**, which is a fourth service but not one this
repository builds.

`nginx` and `php-fpm` *do* share the web container, under supervisord. That
is the one place this topology collapses two processes the local stack keeps
apart, and the reason is narrow: compose can name `app:9000` across
containers for free, Railway bills per service, and a demo box has no reason
to pay for a second service to hold a reverse proxy. They talk over
loopback instead. `docker/production/nginx.conf`'s `fastcgi_pass` is the
only line that has to know.

The worker and the scheduler are deliberately **not** under that
supervisord. Putting them there would make the site's health check govern
whether mail is sent and whether abandoned orders release their stock —
exactly backwards for both.

### `APP_ENV=demo`, not `production`

This is the decision most likely to look like a mistake and is not.

`app()->isProduction()` compares `APP_ENV` against the literal string
`production`. Three guards read it, and all three *refuse to do their job*
when it matches:

- `Database\Seeders\System\UserSeeder` returns early — no accounts at all.
- `App\Console\Commands\SeedDemo` refuses before touching anything.
- every `Database\Seeders\Demo\*` seeder carries the same guard.

Those guards are correct. They exist because every seeded account has a
known password (`UserSeeder`'s own docblock: "a default credential anywhere
else"), and ADR-0003 deliberately keeps staff accounts out of production
reference data.

A box whose entire content is demo data is not the environment those guards
were written to protect. Setting `APP_ENV=production` there would produce an
empty shop with nobody able to log in — and the failure would arrive at
seed time, which is to say while presenting. `APP_ENV=demo` leaves the
guards closed around the real production case and open around this one.

`APP_DEBUG=false` is set independently, because it is not governed by
`APP_ENV` and is what keeps a stack trace off a client's screen.

**Revisit when:** this box holds anything real. At that point `APP_ENV`
becomes `production`, and the first-admin bootstrap command
(`misc/todo.md`, Group B) stops being optional — it is the only way an
administrator exists once `UserSeeder` is inert.

### Trusted proxies, unconditionally

`bootstrap/app.php` now calls `trustProxies(at: '*')` for the four
`X-Forwarded-*` headers. This is the only application-code change the deploy
required.

Railway terminates TLS at its edge and forwards to the container over plain
HTTP. Without trusting that, `$request->isSecure()` is false on every
request and three things break quietly:

1. `url()`/`route()` emit `http://` links on an HTTPS site — mixed content.
2. `config('filesystems.disks.public.url')` is built from `APP_URL`, so
   every product and article image resolves against the wrong scheme. The
   catalogue renders with broken images, which reads as "the seed failed."
3. `SESSION_SECURE_COOKIE=true` is set while the framework believes the
   connection is plaintext — the confusing half of a pair that looks correct
   in the env file.

`at: '*'` trusts whatever fronts the app rather than naming an address.
Railway's edge address is neither stable nor documented, so an allow-list
would be a value to maintain with no way to verify it, and the platform is
the only route to the container. A self-managed host with a fixed proxy
address should name it instead — the call, not a config variable, is where
that changes.

### The production image is a separate Dockerfile

`docker/php/Dockerfile` is not reused. Its own header says "for local
development. Not used in production," and it carries pcov (a coverage
profiler), Playwright and Chromium, mysql-client, and
`git config --system safe.directory '*'`. Those are test-harness concerns;
shipping them would put a browser engine and a profiler on a public host.

The new root `Dockerfile` is three stages — Node builds the Vite bundles,
Composer resolves `--no-dev`, and an Alpine `php:8.4-fpm` runtime receives
only the results. It is at the repository root because Railway autodetects a
root-level Dockerfile with no dashboard configuration, and a setting nobody
has to remember is a setting nobody can set wrong.

`bcmath` is in the runtime extension set and is not optional: ADR-0001
requires every money value to be computed with it, so an image without it
fails at the first cart total rather than at boot.

### Seeding is a manual, one-off step

`railway ssh -- php artisan demo:seed`, by hand, once, after the first
deploy — not `railway run`, which executes on the operator's own machine
with the service's variables injected rather than inside the deployed
container, and so cannot reach `DB_HOST`'s private-network hostname at all.
Not in the entrypoint and not in the start command. (This ADR originally
described a custom Dockerfile with its own entrypoint; ADR-0024 replaces
that image with Railway's own Railpack builder, but the reasoning for manual,
one-off seeding here is unchanged.)

`demo:seed` builds its 140 orders by running the real Actions
(`CreateOrder` → `ReserveStock` → `TransitionOrderStatus` →
`RecordPayment` → `CreateShipment`), which takes minutes and is not
idempotent in any sense a restart could rely on. A seed in the start command
would re-run on every container restart — including a restart triggered by
an env-var change mid-presentation — and reset the data the client is
looking at.

`migrate --force` and the config/route/view caches likewise live in the web
service's start command rather than the entrypoint, so that the worker and
scheduler services (same image) do not each race to migrate on deploy.

### Uploads were accepted as ephemeral — superseded 2026-09-14

**This section originally described a not-yet-hit revisit trigger. The
trigger has been hit; [ADR-0025](0025-split-seed-and-upload-media-disks.md)
is the fix.** Kept here, corrected rather than deleted, because the
original problem statement is still the right context for why ADR-0025
exists — housekeeping on how this is described, not a reopening of the
decision itself.

Railway's filesystem is ephemeral: anything written at runtime is lost on
redeploy. Before ADR-0025, `ProductImage::DISK` and `Article::IMAGE_DISK`
were both the `public` disk, i.e. `storage/app/public`, for both seeded and
uploaded content alike.

No volume was mounted. The 182 committed demo images live in the repository
and therefore inside the image, so the seeded catalogue rendered correctly
after any redeploy regardless. What was lost was an image *uploaded through
the admin panel* after deploy.

**Correction to the original text:** the mount path this section used to
name, `/var/www/html/storage/app/public`, was never correct for this
project's actual Railway build — that path belongs to the custom Dockerfile
[ADR-0024](0024-railpack-over-custom-image.md) replaced. Under Railpack the
application root is `/app`, confirmed via `railway ssh`; a volume mounted at
the old path would have attached without error and simply never been read.
ADR-0025 has the corrected path and the full fix.

### Known-password admin accounts are accepted, explicitly

`admin@example.com` and its three siblings keep the passwords
`UserSeeder`'s factory gives them, on a box with a public URL. This is a
real exposure and it is accepted because there is nothing behind it: demo
catalogue, demo orders, Stripe test keys, no real customer data, no real
money.

It is also what makes the beta demonstrable — §37 criterion 18 ("user roles
cannot access prohibited features") can only be *shown* with an account per
role to show it with.

**Revisit when:** the box stops being a demo, or sooner if the URL is shared
beyond the client. Mitigations, in order of effort: change the password
after seeding, or delete the Railway service when the presentation ends.

## Consequences

**+** A URL exists without anyone requesting a VPS, and without repository
admin rights — `railway up` from a local checkout deploys the working
directory directly. The GitHub App route (autodeploy on push) needs the org
owner to install it, which is a request that can happen in parallel rather
than a blocker.

**+** The deployed topology is in the repository and diffable. `server_tokens
off` and `expose_php = Off` now exist in a file that actually reaches a host
— SEC-004's two halves are closed in the deployed config rather than owed
to it.

**+** `config()`-only access to settings (implementation standard #16) pays
off concretely: `config/orders.php`'s `unpaid_ttl_minutes` and the cart caps
are tunable from the Railway dashboard with no deploy, exactly as their
docblocks claim.

**−** Editing the running app is not possible, and that is a real change
from Forge. No SSH, no persistent shell, `opcache.validate_timestamps=0`,
and an immutable image: every change is a rebuild from source.
`railway run <cmd>` is the only administrative access, and it is enough
(`demo:seed`, `migrate`, `tinker`).

**−** Two Dockerfiles and two nginx configs now exist, and the dev pair
still never reaches a host. The drift risk is real and is why
`docker/production/nginx.conf` states which directives are duplicated on
purpose (`server_tokens`, the nosniff header) rather than leaving a reader to
diff them.

**−** Railway's scheduler reliability for Laravel is reported as uneven, and
`orders:expire-unpaid` is the one command here that is genuinely
time-sensitive — ADR-0022's stock release depends on it. **This is
unverified on Railway and must be checked on the box, not assumed.** If it
proves unreliable, the sweep is also reachable manually
(`railway ssh -- php artisan orders:expire-unpaid`) which is adequate for a
presentation and not for anything more.

**−** The Stripe webhook IP allow-list
(`docker/nginx/stripe-ip-allowlist.conf.example`) cannot be carried over as
written: it matches `$remote_addr`, which behind Railway's edge is the proxy,
not Stripe. Enabling it unchanged would reject every genuine event while
looking exactly like a signature failure. Signature verification
(`VerifyStripeWebhookSignature`, unconditional) is what authenticates the
endpoint; the allow-list was always the second of two layers, and the
deployed config omits it deliberately.

**−** HSTS is still not set anywhere. It is the one security header
`SetSecurityHeaders` does not add, it belongs at the edge, and Railway's
edge is not this repository's to configure. Unresolved rather than decided.

**−** Hobby tier or better is required. The Free tier's 0.5 GB per service
is below what `demo:seed` needs — this codebase already requires a raised
CLI memory limit (`docker/php/conf.d/cli-memory.ini`) and `--memory-limit=1G`
for Larastan — and four services would each be capped there.

## Alternatives considered

**Forge, as ADR-0001 said.** Rejected for this beta only: it needs a VPS
nobody has provisioned and an account nobody holds. The decision for a real
production deploy is genuinely still open, and this ADR does not close it —
it supersedes ADR-0001's hosting line for the beta, not for whatever
production eventually becomes.

**Railway's Laravel autodetection (Railpack, php-fpm + Caddy).** Rejected:
zero configuration, but the build is outside the repository's control and
not reproducible locally, and nothing would record how production is built.
For a project whose documentation discipline is this heavy, an
unexplainable build is the wrong trade.

**`APP_ENV=production` with the seeder guards relaxed.** Rejected outright.
The guards are correct and the demo is the thing that should bend, not the
protection around real production. Editing a guard to make a demo work is
how the guard stops being trustworthy.

**One container running everything under supervisord** (web + worker +
scheduler). Rejected: see the table above. A queue worker crash should not
fail the site's health check, and the scheduler's cadence should not reset
when the web process restarts.
