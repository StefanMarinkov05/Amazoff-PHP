# How to deploy and host this app

Two targets, and only one of them has ever run.

**Railway** is where the client-facing beta deploys, from a container image
this repository owns ([ADR-0023](../adr/0023-railway-beta-deploy-target.md)).
That is the part below with commands in it.

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

The root `Dockerfile`, three stages: Node builds Vite's bundles, Composer
resolves `--no-dev`, and an Alpine `php:8.4-fpm` runtime receives only the
results, with nginx and php-fpm under supervisord.
`docker/production/nginx.conf` and `supervisord.conf` are its config.

`docker/php/Dockerfile` is **not** used. It says so in its own header, and it
carries pcov, Playwright/Chromium and mysql-client — test-harness concerns
that must not reach a public host.

`.dockerignore` is load-bearing, not housekeeping: without it `COPY src/ ./`
bakes the host's gitignored-but-present `src/.env` into the image, where
Laravel reads it in preference to Railway's injected variables. That presents
as "Railway ignored my settings."

### Four services

Three are built from the same image with different start commands; MySQL is
Railway's own.

| Service | Start command |
|---|---|
| **web** | `php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan view:cache && /usr/local/bin/docker-entrypoint.sh` |
| **worker** | `php artisan queue:work --tries=3 --max-time=3600 --sleep=1` |
| **scheduler** | `while true; do php artisan schedule:run --no-interaction; sleep 60; done` |
| **MySQL** | Railway-managed; add from the dashboard |

`migrate --force` and the caches live in the **web** service's start command
only — not in the image's entrypoint — so the worker and scheduler do not
each race to migrate on deploy.

The worker and scheduler are deliberately not folded into the web container's
supervisord: a worker crash must not fail the site's health check, and the
scheduler's cadence must not reset whenever the web process restarts.

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
| `MAIL_CONTACT_NOTIFICATION_ADDRESS` | the inbox staff actually read | Where each contact-form message is emailed. Unset, it falls back to `MAIL_FROM_ADDRESS` — usually a `noreply@` nobody reads, so messages pile up in the panel with no one told. |
| `STRIPE_KEY` / `STRIPE_SECRET` | test keys | `pk_test_` / `sk_test_`. `demo:stripe-payments` refuses a non-`sk_test_` secret. |
| `STRIPE_WEBHOOK_SECRET` | from the dashboard endpoint | **Not** from `stripe listen`. Create an endpoint at `https://<domain>/stripe/webhook` and copy its signing secret. |
| `PORT` | *do not set* | Railway injects it; the entrypoint substitutes it into nginx's config. |

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
2. Add the **MySQL** service; wire `DB_*` from its variables.
3. Set every variable in the table above.
4. Deploy the **web** service and wait for `/up` to answer.
5. **Seed, once, by hand:**
   ```bash
   railway run php artisan demo:seed
   ```
   Not in a start command — it walks 140 orders through the real Actions,
   takes minutes, and a restart mid-presentation would reseed and reset what
   the client is looking at.
6. Optionally open real Stripe test intents on the seeded orders, so the
   panel shows real intent ids:
   ```bash
   railway run php artisan demo:stripe-payments
   ```
7. Add the **worker** and **scheduler** services from the same image.
8. **Verify, in a browser, not by reading config:** images render on the
   catalogue (that is the `trustProxies`/`APP_URL` check), `/admin/login`
   accepts `admin@example.com`, and a card checkout reaches Stripe.

### Known accepted trades

Each of these is recorded with a "revisit when" in ADR-0023:

- **Uploads are ephemeral.** No volume is mounted. The 182 committed demo
  images survive a redeploy by being *in* the image; an image uploaded
  through the admin panel afterwards does not. Fix when needed: a volume at
  `/var/www/html/storage/app/public`, or the `s3` disk
  `config/filesystems.php` already defines. Railway volumes are not mounted
  at build or pre-deploy time, which is why `storage:link` runs in the
  entrypoint.
- **The seeded admin accounts keep their known passwords** on a public URL.
  Accepted because there is nothing behind it — demo catalogue, demo orders,
  Stripe test keys. It is also what makes §37 #18 demonstrable. Mitigate by
  changing the password after seeding, or by deleting the service when the
  presentation ends.
- **`orders:expire-unpaid` reliability on Railway is unverified.** ADR-0022's
  stock release depends on it firing every minute. Check it on the box;
  `railway run php artisan orders:expire-unpaid` is the manual fallback.
- **No HSTS.** The one security header `SetSecurityHeaders` does not set, and
  it belongs at an edge this repository does not configure.
- **Editing the running app is not possible.** No SSH, no persistent shell,
  `opcache.validate_timestamps=0`, immutable image. `railway run <cmd>` is
  the administrative access, and it is enough (`demo:seed`, `migrate`,
  `tinker`). Every file change is a rebuild from source.
- **Hobby tier or better.** Free's 0.5 GB per service is below what
  `demo:seed` needs — this codebase already requires a raised CLI memory
  limit (`docker/php/conf.d/cli-memory.ini`) and `--memory-limit=1G` for
  Larastan.

### What the deployed config already covers

These were owed to production and are now set in files that actually reach a
host, rather than only in the dev stack:

- `server_tokens off` (nginx) and `expose_php = Off` (php.ini) — SEC-004.
- `X-Content-Type-Options` on static assets, with `always` — SEC-007.
- Everything else — `X-Frame-Options`, `Referrer-Policy`,
  `Permissions-Policy`, the CSP — comes from
  `App\Http\Middleware\SetSecurityHeaders`, appended globally in
  `bootstrap/app.php`, so it covers every response PHP renders including
  Filament's. nginx deliberately does not restate those: two sources for one
  header is how they disagree.

**The Stripe webhook IP allow-list is deliberately omitted.**
`docker/nginx/stripe-ip-allowlist.conf.example` matches `$remote_addr`, which
behind Railway's edge is the proxy rather than Stripe — enabling it unchanged
rejects every genuine event while looking exactly like a signature failure.
Signature verification (`VerifyStripeWebhookSignature`, unconditional) is
what authenticates the endpoint; the allow-list was always the second of two
layers.

---

## Forge — never provisioned, checklist retained

ADR-0001 named Forge; ADR-0023 supersedes that line. Nothing below has been
exercised against a real host. It is kept because it remains the correct list
of what is true in dev only by accident of dev's own environment, and a VPS
deploy would owe all of it.

Forge provisions its own nginx and PHP-FPM, so none of this repo's
`docker/nginx/*.conf` **or** `docker/production/nginx.conf` reaches it —
anything fixed only in either is owed again there:

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
