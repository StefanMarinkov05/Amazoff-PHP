# How to deploy and host this app

Two targets, and only one of them has ever run.

**Railway** is where the client-facing beta deploys, built by Railway's own
Railpack builder ([ADR-0023](../adr/0023-railway-beta-deploy-target.md) for
the platform decision, [ADR-0024](../adr/0024-railpack-over-custom-image.md)
for why it's Railpack and not a custom image). That is the part below with
commands in it.

**Forge** is what [ADR-0001](../adr/0001-tech-stack-selection.md) originally
named, and its hosting line is superseded. No Forge host has ever been
provisioned; the checklist near the bottom is kept because it is still the
honest list of what a VPS deploy would owe, not because anything runs there.

The three topologies side by side — why nothing here is automatic:
[view source](../reference/diagrams/deployment/deployment.puml) ·
[view PDF](../reference/diagrams/deployment/deployment.pdf) (the rendered
files lag the source; regenerate per
[the diagram README](../reference/diagrams/README.md)).

---

## Railway — the beta box

### What gets built

Nothing in this repository. Railway's Railpack builder detects Laravel from
the presence of `artisan` in the service's Root Directory (`src`, since the
app lives under `src/` per the repository root's own `CLAUDE.md`) and builds
a FrankenPHP/Caddy image on its own — no Dockerfile, no nginx config, no
supervisord, no manual `$PORT` handling. ADR-0024 has the reasoning: a
hand-written image for this failed eleven consecutive deploys before it was
abandoned.

`docker/php/Dockerfile` remains for local dev only. It says so in its own
header, and it carries pcov, Playwright/Chromium and mysql-client —
test-harness concerns with no bearing on Railway's build.

### Railpack-specific settings this deploy needs

Read from Railpack's own PHP provider source (its docs and source disagree
on some variable names, so both forms are set below):

- **`RAILPACK_SKIP_MIGRATIONS=true`.** Railpack's own startup script runs
  `php artisan migrate --force` on every container start unless this is set.
  Migrations instead run in the **web** service's `preDeployCommand`, so a
  bad migration fails the deploy rather than crash-looping the running
  container.
- **`RAILPACK_PHP_EXTENSIONS` and `PHP_EXTENSIONS`**, both set to
  `intl,zip,bcmath,gd,exif,pcntl,pdo_mysql`. Railpack collects extensions
  from `composer.json`'s `require` section and this variable — never from
  `composer.lock` — and this project's `composer.json` declares no `ext-*`
  requirements at all, so without this the build would be missing `intl`
  (Filament) and the rest.
- **`RAILPACK_NODE_VERSION=22`.** Vite 8 needs Node ≥ 22.12; Railpack
  otherwise resolves whatever `lts` means when the build runs.

### Three services

| Service | Start / pre-deploy | Root Directory |
|---|---|---|
| **web** | Railpack default start; pre-deploy `php artisan migrate --force --no-interaction --schema-path=/nonexistent` | `src` |
| **worker** | `php artisan queue:work --tries=3 --max-time=3600 --sleep=1` | `src` |
| **scheduler** | `php artisan schedule:work` | `src` |
| **MySQL** | Railway-managed | — |

The `--schema-path=/nonexistent` flag on the migrate command matters only on
a from-scratch database: the FrankenPHP image has no `mysql` client, so a
schema-dump load (`database/schema/mysql-schema.sql`, committed for local
speed) would fail the way ADR-0024's troubleshooting entry describes.
Laravel's own `MigrateCommand` only attempts that load when no migration has
run yet, so the flag is inert after the first deploy and only guards the
first one.

`schedule:work` runs the scheduler in-process rather than the
`while true; do schedule:run; sleep 60; done` loop the old Dockerfile
approach needed — Railpack's shell-form start command supports it directly.

The worker and scheduler are separate services, not folded into the web
process: a worker crash must not fail the site's health check, and the
scheduler's cadence must not reset whenever the web process restarts.

**Not yet created, as of 2026-09-13.** Only `Online_Shop_TeamB` (web) and
`MySQL` exist in the project. Planned here, verified against Railpack's
actual startup contract, not created — creating a service is a deliberate
step, not a byproduct of writing this section.

**Both new services need `RAILPACK_SKIP_MIGRATIONS=true`, same as web.**
This is easy to miss because neither service has a `preDeployCommand` the
way web does — but `src/start-container.sh` (the durable Filament-assets
fix, ADR-0024) is shared by every service built from this repo, and it
carries forward Railpack's default guard:
```bash
if [ "$RAILPACK_SKIP_MIGRATIONS" != "true" ]; then
  php artisan migrate --force
fi
```
Without the variable set, a worker or scheduler container migrates on every
boot — racing the web service's own pre-deploy migration, and, worse, racing
each other every time either service restarts or redeploys. There is
nothing in a worker or scheduler's own config that skips this; it has to be
set explicitly per service.

**Same Root Directory (`src`), same builder, same repo — different start
command only.** Both should use `RAILPACK_SKIP_MIGRATIONS=true`,
`PHP_EXTENSIONS`/`RAILPACK_PHP_EXTENSIONS`, `RAILPACK_NODE_VERSION`, and
every `DB_*`/`APP_*`/`STRIPE_*`/`SESSION_*` variable the web service has —
the queue and scheduler commands touch the same database, mailers, and
Stripe config the web process does. No `preDeployCommand` and no
`healthcheckPath` on either: Railway doesn't route HTTP traffic to them, so
there's nothing for a healthcheck to hit, and migrations belong to web
alone.

Because GitHub autodeploy is connected (confirmed 2026-09-13 — see below),
creating either service from the same repo means it inherits that behaviour
too: a future push to `main` rebuilds and restarts the worker/scheduler
alongside web, not just web.

### Environment variables

Set these in Railway. The notes are the point — each records a failure that
is quiet rather than loud.

| Variable | Value | Why |
|---|---|---|
| `APP_ENV` | `demo` | **Never `production`.** `app()->isProduction()` matches that literal string, and `UserSeeder`, `SeedDemo` and every `Demo*` seeder refuse to run when it does. `production` here gives an empty shop with nobody able to log in — discovered at seed time, i.e. while presenting. |
| `APP_DEBUG` | `false` | Independent of `APP_ENV`. Keeps stack traces off a client's screen. |
| `APP_KEY` | generate once | `php artisan key:generate --show` locally, then paste. Never regenerate — it decrypts existing data. |
| `APP_URL` | `https://<service>.up.railway.app` | `config/filesystems.php` builds the `public` disk's URL from it, so every product and article image resolves against it. Wrong value = a catalogue of broken images. |
| `SESSION_SECURE_COOKIE` | `true` | Railway serves HTTPS. Pairs with `trustProxies` — see below. |
| `DB_*` | from the MySQL service | `DB_HOST` is Railway's private hostname, never `127.0.0.1`. |
| `COUPON_EMAIL_PEPPER` | any long random string | `RedeemCoupon` hashes emails with it. Blank is not a weaker hash, it is a different one than the rows were written with. |
| `MAIL_MAILER` | `log` (or a real relay) | `mailpit` is a compose hostname that does not resolve here; queued mail fails silently against it. |
| `STRIPE_KEY` / `STRIPE_SECRET` | test keys | `pk_test_` / `sk_test_`. `demo:stripe-payments` refuses a non-`sk_test_` secret. |
| `STRIPE_WEBHOOK_SECRET` | from the dashboard endpoint | **Not** from `stripe listen`. Create an endpoint at `https://<domain>/stripe/webhook` and copy its signing secret. **Done, 2026-09-13** — endpoint created on `acct_1U9BTmEinvfvnBsb` (the canonical account; see the account-mismatch trap below), 7 events (`payment_intent.succeeded`/`.payment_failed`/`.canceled`/`.processing`, `charge.refunded`, `charge.dispute.created`/`.closed` — the exact set `HandleStripeWebhookEvent` handles). Verified by a real Stripe-signed test event in the Railway HTTP logs: `POST /stripe/webhook`, UA `Stripe/1.0`, genuine `Stripe-Signature` header, **200**. Not verified by a curl 400/405, which only proves the middleware rejects garbage — that was mistaken for "done" once this session before the real endpoint existed. |
| `RAILPACK_SKIP_MIGRATIONS` | `true` | See above. |
| `RAILPACK_PHP_EXTENSIONS`, `PHP_EXTENSIONS` | `intl,zip,bcmath,gd,exif,pcntl,sockets` | See above. `pdo_mysql` and `mbstring` are **not** in this list as of `6967966` ("declare the PHP extensions this app has always required") — they arrive instead via `composer.json`'s `require` block, which Railpack reads independently of this env var. Confirmed live: `php -m` on the deployed container lists `pdo_mysql` despite its absence here. ADR-0024's text still says `composer.json` declares no `ext-*` requirements; that was true when written and is stale now — the nine `ext-*` entries postdate it. |
| `RAILPACK_NODE_VERSION` | `22` | See above. |
| `PORT` | *do not set* | Railway injects it; Railpack's Caddyfile binds it on all interfaces, IPv4 and IPv6, on its own. |

`TRUSTED_PROXIES` is not a variable here. `bootstrap/app.php` calls
`trustProxies(at: '*')` unconditionally, because Railway's edge address is
neither stable nor documented. Without it `$request->isSecure()` is false on
every request: `http://` links on an HTTPS site, image URLs built against the
wrong scheme, and a `Secure` cookie the framework believes it cannot send.

### First deploy, in order

1. **Get the code to Railway.** `railway up` from a local checkout needs no
   repository permissions at all. The GitHub-App route (autodeploy on push)
   needs the **org owner** to install the Railway GitHub App and grant it
   access — a request worth making in parallel, not a blocker.

   **Resolved 2026-09-13: this is done.** The Railway GitHub App is
   installed and the `Online_Shop_TeamB` service's source is `main` in
   `Lumen101-Internship-2026/Online_Shop_TeamB`. Confirmed by observation,
   not configuration: merging PR #90 to `main` triggered a build and deploy
   (`7e50a6b2`) within seconds, with no `railway up` involved. **Practical
   consequence:** merging to `main` now deploys the beta immediately and
   automatically — there is no staging gap between "PR merged" and "live for
   anyone with the URL." Treat a merge to `main` with the same weight as
   running `railway up` by hand.
2. Add the **MySQL** service; wire `DB_*` from its variables.
3. Set every variable in the table above, and the service's Root Directory
   to `src`.
4. Deploy the **web** service and wait for `/up` to answer.
5. **Seed, once, by hand, over SSH — not `railway run`:**
   ```bash
   railway ssh -- php artisan db:seed --force
   railway ssh -- php artisan demo:seed
   ```
   `railway run` executes **on your own machine** with the service's
   variables injected — `DB_HOST` there is Railway's private hostname,
   unreachable outside Railway's network, so `railway run` cannot actually
   seed the deployed database. `railway ssh` opens a session inside the
   running container, where that hostname resolves. `db:seed` first because
   `demo:seed` assumes roles, permissions, carriers and staff accounts
   already exist — true locally where `migrate:fresh --seed` runs both in
   one step, not true here where only `migrate` runs pre-deploy. Seeding is
   never a start-command step: `demo:seed` walks 140 orders through real
   Actions, takes minutes, and a restart mid-presentation would reseed and
   reset what the client is looking at.
6. Optionally open real Stripe test intents on the seeded orders, so the
   panel shows real intent ids:
   ```bash
   railway ssh -- php artisan demo:stripe-payments
   ```
7. Add the **worker** and **scheduler** services, same repo and Root
   Directory, different start commands (table above).
8. **Verify, in a browser, not by reading config:** images render on the
   catalogue (that is the `trustProxies`/`APP_URL` check), `/admin/login`
   accepts `admin@example.com`, and a card checkout reaches Stripe.

### Known accepted trades

Each of these is recorded with a "revisit when" in ADR-0023 or ADR-0024:

- **Uploads are ephemeral.** No volume is mounted. The 182 committed demo
  images survive a redeploy by being *in* the repository; an image uploaded
  through the admin panel afterwards does not. Fix when needed: a Railway
  volume mounted at the `public` disk's path, or the `s3` disk
  `config/filesystems.php` already defines.
- **The seeded admin accounts keep their known passwords** on a public URL.
  Accepted because there is nothing behind it — demo catalogue, demo orders,
  Stripe test keys. It is also what makes §37 #18 demonstrable. Mitigate by
  changing the password after seeding, or by deleting the service when the
  presentation ends.
- **`orders:expire-unpaid` reliability on Railway is unverified — and still
  is, because the scheduler service does not exist yet.** ADR-0022's stock
  release depends on it firing every minute via a dedicated scheduler
  service's `schedule:work`, and as of 2026-09-13 there are only two
  services in this project (`Online_Shop_TeamB`, `MySQL`) — no worker, no
  scheduler. Until one exists, `orders:expire-unpaid` never runs on its own
  on the beta; `railway ssh -- php artisan orders:expire-unpaid` is the only
  way it fires, by hand.
- **The queue has no worker either**, for the same reason. `QUEUE_CONNECTION=database`
  and the `jobs` table both exist, but nothing calls `queue:work`, so the
  three `ShouldQueue` mailables (`OrderPlaced`, `NewsletterConfirmation`,
  `NewsletterUnsubscribed`) queue and never send until a worker service is
  added.
- **No HSTS.** The one security header `SetSecurityHeaders` does not set, and
  it belongs at an edge this repository does not configure.
- **Static asset headers under Caddy are not configured.** The nginx config
  the previous Dockerfile approach used to set `X-Content-Type-Options` on
  static files (SEC-007) has no Railpack/Caddy equivalent here. Recorded as a
  gap, not fixed.
- **Editing the running app is not possible.** `railway ssh` opens a shell,
  but the image is rebuilt from source on every deploy; a change made inside
  a live session does not survive a redeploy. Every real change is a
  rebuild from source.
- **Hobby tier or better.** Free's 0.5 GB per service is below what
  `demo:seed` needs — this codebase already requires a raised CLI memory
  limit (`docker/php/conf.d/cli-memory.ini`) and `--memory-limit=1G` for
  Larastan.

### What the deployed config covers, and what it doesn't

`App\Http\Middleware\SetSecurityHeaders`, appended globally in
`bootstrap/app.php`, covers every response PHP renders including Filament's:
`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`,
`Permissions-Policy`, and the CSP. That runs regardless of builder.

**Not covered under Railpack/Caddy:** the nosniff header on *static* assets
(files Caddy serves directly, never reaching PHP) and version-banner
suppression — both of which the abandoned nginx config used to set
explicitly. Neither has a configured Caddy equivalent yet.

**The Stripe webhook IP allow-list was never enabled here and stays that
way.** It matches `$remote_addr`, which behind any reverse proxy — Railway's
included — is the proxy rather than Stripe, so enabling it unchanged rejects
every genuine event while looking exactly like a signature failure.
Signature verification (`VerifyStripeWebhookSignature`, unconditional) is
what actually authenticates the endpoint; the allow-list was always meant as
a second layer for a self-managed nginx.

---

## Forge — never provisioned, checklist retained

ADR-0001 named Forge; ADR-0023 supersedes that line. Nothing below has been
exercised against a real host. It is kept because it remains the correct list
of what is true in dev only by accident of dev's own environment, and a VPS
deploy would owe all of it.

Forge provisions its own nginx and PHP-FPM, so none of this repo's
`docker/nginx/*.conf` reaches it — anything fixed only there is owed again:

- `SESSION_SECURE_COOKIE=true` once served over HTTPS. `config/session.php`
  defaults to null when unset, which is the same as false — an unset variable
  is a silently insecure deploy rather than an error anyone sees. SEC-013,
  recorded as **half closed** for exactly this reason.
- `add_header X-Content-Type-Options "nosniff" always;` on static assets
  (SEC-007).
- `server_tokens off;` (nginx) and `expose_php = Off;` (`php.ini`) to
  suppress version banners (SEC-004).
- **Stripe webhook IP allow-list.**
  `docker/nginx/stripe-ip-allowlist.conf.example` has the full setup. Fetch
  the current list (`curl https://stripe.com/files/ips/ips_webhooks.txt`, do
  not trust the snapshot in that file), include it inside the
  `/stripe/webhook` location block, and if TLS terminates at a load balancer
  or CDN set `real_ip_header`/`set_real_ip_from` first or every request is
  denied. Deliberately not enabled in dev, because `stripe listen --forward-to`
  delivers from a Docker bridge address and an allow-list there rejects every
  forwarded event while looking like a broken signature.
- A queue worker and a scheduler, which §22 and §28 require and which
  ADR-0001's own "Defects in the issued document" note flags as impossible on
  the spec's suggested hosting.
- `trustProxies` — already in `bootstrap/app.php` with `at: '*'`. A VPS with
  a fixed, known proxy address should name it there instead of trusting any.

## What this file is not

Not a zero-downtime runbook. Railway redeploys by replacing the container;
there is no blue/green story here, and for a demo box that is fine. Write one
when something depends on it.
