# ADR-0024: Railway builds this app with Railpack, not a custom Dockerfile

Status: Accepted
Date: 2026-09-12 · Deciders: Stefan Marinkov

How the beta's container image is built. Supersedes only the image-building
half of [ADR-0023](0023-railway-beta-deploy-target.md) — Railway as the
platform, `APP_ENV=demo`, `trustProxies`, manual seeding, and the accepted
trades all stand unchanged from that ADR.

## Context

ADR-0023's root `Dockerfile` (three stages: Node assets, Composer, an Alpine
`php:8.4-fpm` runtime with nginx and php-fpm under supervisord) never reached
a working deploy. Eleven consecutive attempts, each fixing a real defect and
each replaced by a new one one layer further in:

1. `composer:2`'s PHP lacks `intl` — build-time platform requirement error.
2. `RUN --mount=from=composer:2,...` — invalid on Railway's builder, which
   supports only `type=cache` mounts; builds fine under local BuildKit,
   which is more permissive.
3. `apk del .build-deps` stripped the runtime libraries the compiled
   extensions link against — they compiled, wrote their `.ini` files, and
   could not load.
4. The committed schema dump (`database/schema/mysql-schema.sql`) made
   `migrate` shell out to a `mysql` client binary the image didn't have.
5. Once installed, that client (Alpine's, which is MariaDB's) rejected
   Railway MySQL's self-signed certificate.
6. Fixed with a client TLS setting; MariaDB's client then turned out unable
   to authenticate to MySQL 8 at all — no `caching_sha2_password` plugin.
   Structural, not configurable. Fixed by excluding the dump so `migrate`
   replays 68 migrations over PDO instead, which never shells out.
7. Migrations then ran and the container started, and the deploy failed at
   the healthcheck stage regardless — nine further attempts (`PORT`, the
   domain's `targetPort`, removing the healthcheck entirely, binding nginx
   on IPv6) each addressed a real gap and none fixed it. The proximate
   cause of that last failure was never confirmed: Railway's start command
   for a Dockerfile-based service runs in **exec form, not a shell**, so a
   command built as `a && b && c` is invalid there even though every local
   `docker run` wrapped it in `sh -c` and never exercised that path.

Full detail, findings and dead ends alike, in
`troubleshooting/infra-and-environment.md`'s "Railway deploy: eleven
failures, one Dockerfile" entry — kept in full because the individual
findings (BuildKit mount support, the apk runtime-library trap, the
MariaDB/MySQL 8 auth plugin gap, exec-form start commands) recur independently
of which builder ships next.

**The pattern common to all eleven:** each fix was verified by building and
running the image locally, and each verification was true and irrelevant.
Docker's local `docker build` and `docker run` are more permissive than
Railway's builder and runtime in ways that only show up on the platform
itself — a local green run proves the image is *internally* consistent, not
that Railway's specific builder and proxy will accept it.

## Decision

**Railway's own Railpack builder**, Laravel detected automatically from
`artisan`. No Dockerfile, no nginx config, no supervisord, no manual `$PORT`
substitution, no manual IPv6 binding. This is Railway's documented,
supported path for exactly this framework, and every one of the eleven
failures above was a problem *with the custom image*, never with the
application it wrapped.

### Root Directory, not a build context trick

The service's Root Directory is set to `src`. Two consequences, both
intended: Railpack's build context is `src/` alone, so it never sees a stray
Dockerfile at the repository root (there being none, after this ADR, is
itself the point — one is not accidentally picked up later); and
`railway.json`, if ever added, would need its path stated explicitly
relative to the repository root regardless of Root Directory, per Railway's
own documented exception for config files in a monorepo.

### What Railpack does differently, and what each difference costs

Read from Railpack's own PHP provider source, not assumed:

- **`composer install` runs with `--no-scripts`.** `filament:upgrade`
  (`composer.json`'s `post-autoload-dump`) never runs during build. If the
  Filament CSS/JS/font paths 404 in production, the fix is a committed
  `start-container.sh` override that runs `php artisan filament:assets`
  before handing off to Railpack's own startup, not reverting to a
  Dockerfile.
- **PHP extensions come from `composer.json`'s `require` section and an env
  var, never from `composer.lock`.** This project's `composer.json` declares
  no `ext-*` requirements — the extensions are implicit in what Laravel,
  Filament and the Saloon connectors need — so `RAILPACK_PHP_EXTENSIONS` is
  set explicitly: `intl,zip,bcmath,gd,exif,pcntl,pdo_mysql`. Also set as
  `PHP_EXTENSIONS`, because Railpack's own documentation and its source
  disagree on the variable name.
- **Railpack's own startup script runs `migrate` on every container start**,
  unconditionally unless `RAILPACK_SKIP_MIGRATIONS=true`. Set to `true`
  deliberately, so migrations stay exactly where ADR-0023 already put them —
  the service's `preDeployCommand`, which fails the deploy rather than
  crash-looping the running container on a bad migration.
- **The pre-deploy migrate command carries
  `--schema-path=/nonexistent`.** The FrankenPHP image Railpack builds has
  no `mysql` client at all, so a schema-dump load would fail exactly as
  attempt 4 above did on the custom image, on a database with no migrations
  recorded yet. Laravel's own `MigrateCommand` only attempts the schema-path
  load when `hasRunAnyMigrations()` is false, so the flag is inert once the
  first migration has run and only matters on a from-scratch database —
  which describes both this deploy and any future one that starts fresh.
- **Vite 8 needs Node ≥ 22.12.** `RAILPACK_NODE_VERSION=22` pins it, rather
  than trusting whatever `lts` resolves to when this deploys again in six
  months.
- **Caddy, not nginx, serves the app**, on `{$PORT}` bound to all interfaces
  including IPv6 by Railpack's own Caddyfile — the exact gap the custom
  image's final three attempts existed to patch, closed by construction.
  `SetSecurityHeaders` (global PHP middleware) still applies to every
  document response; Caddy's own headers on static assets are not
  configured and are a known gap, same as they were under nginx.

### Seeding uses `railway ssh`, not `railway run`

`railway run` executes the command **on the operator's own machine**, with
the service's variables injected — it does not run inside the deployed
container. `DB_HOST` there resolves to `<service>.railway.internal`, which
is only reachable from inside Railway's private network. Every `railway run
php artisan demo:seed` instruction in ADR-0023 and the docs it touches was
therefore wrong and is corrected to `railway ssh -- php artisan demo:seed`,
which opens a session inside the running container.

`db:seed --force` (roles, permissions, carriers, staff accounts) must run
before `demo:seed`, which assumes all four already exist — true locally
because `migrate:fresh --seed` runs both in one step, not true here where
only `migrate` runs pre-deploy.

## Consequences

**+** No image-layer surface left to debug. Every one of the eleven prior
failures was in code this ADR deletes.

**+** The build is Railway's own well-trodden path for Laravel, not a
bespoke reconstruction of it — the failure modes from here on are ones
Railway's own users hit and document, not ones unique to this repository's
image.

**−** The build is no longer reproducible with a local `docker build`. A
change to build behaviour can only be verified by deploying, which is
slower feedback than the custom image offered *in principle* — though in
practice, across eleven attempts, that local reproducibility never once
predicted the platform's actual behaviour, so the practical loss is smaller
than it looks.

**−** `RAILPACK_PHP_EXTENSIONS` is now the single source of truth for the
deployed extension set and lives only in Railway's dashboard/variables, not
in a committed file the way the Dockerfile's `docker-php-ext-install` line
was. A drift between this list and what `docker/php/Dockerfile` installs for
local dev is now possible and not statically checked.

**−** Static asset headers (SEC-004's nosniff, `server_tokens`-equivalent)
are not configured under Caddy, the same gap the custom nginx config closed
and this ADR reopens. Recorded, not fixed, here.

## Alternatives considered

**Keep debugging the custom Dockerfile.** Rejected on the strength of the
pattern, not any single failure: eleven fixes each addressing a real
mechanism and each superseded by the next is evidence about the
image-building approach, not about which specific line was still wrong.

**A committed `railway.json` pinning `builder: DOCKERFILE`** to keep the
custom image but make the choice explicit. Rejected for the same reason as
above — it would have kept every future maintainer debugging the same class
of platform-specific build/runtime mismatch this ADR exists to leave.
