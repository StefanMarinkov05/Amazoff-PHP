# How to deploy and host this app

Deployment target is Laravel Forge (`docs/adr/0001-tech-stack-selection.md`).
This file is the checklist of things that are correct in local dev **only
because of dev's environment**, and must be set deliberately on the real
host — not discovered by an incident after launch. It starts small and grows
as each area gets its own deploy rule; add to it rather than starting a
second file.

## Environment variables to set before first deploy

- **`SESSION_SECURE_COOKIE=true`.** Unset locally (correctly — a `Secure`
  cookie is not sent over plain `http://localhost`, and setting it there
  would silently break every session). `.env.example` now documents the key,
  but documenting it is not setting it: this must be turned on explicitly in
  Forge's environment editor once the site is served over HTTPS, or the
  session cookie can be sent over plaintext HTTP if any request ever reaches
  the site that way. See `reference/testing/security-testing.md` SEC-013 for
  the full finding — it is recorded there as **half closed** for exactly
  this reason, and stays half closed until this step happens on the real
  host.

## Server config to set independently of the app (nginx, PHP-FPM)

Forge provisions its own nginx and PHP-FPM — none of this repo's
`docker/nginx/*.conf` reaches production, so anything fixed only there is
owed again here:

- `add_header X-Content-Type-Options "nosniff" always;` on static assets
  (SEC-007).
- `server_tokens off;` (nginx) and `expose_php = Off;` (`php.ini`) to
  suppress version banners (see `reference/testing/security-testing.md`,
  "What held").
- **Stripe webhook IP allowlist.** `docker/nginx/stripe-ip-allowlist.conf.example`
  has the full setup — deliberately not enabled here, because
  `stripe listen --forward-to` delivers local test events from a Docker
  bridge address, never from Stripe's published ranges, so turning this on
  in dev rejects every forwarded event and looks exactly like a broken
  signature. On the real host: fetch the current list
  (`curl https://stripe.com/files/ips/ips_webhooks.txt`, do not trust the
  snapshot in that file), include it inside the `/stripe/webhook` location
  block, and if TLS terminates at a load balancer or CDN set
  `real_ip_header`/`set_real_ip_from` first or every request is denied. This
  is one of two layers Stripe expects together — signature verification
  (`App\Http\Middleware\VerifyStripeWebhookSignature`) already runs
  unconditionally and is not affected by whether this is enabled.

## What this file is not

Not a full runbook (build steps, zero-downtime deploy, queue workers,
scheduler) — that gets written when this project actually deploys for the
first time. This is specifically the list of environment-dependent security
settings that are correct in dev *by accident of the environment* and need a
deliberate decision on the real host.
