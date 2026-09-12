#!/bin/bash

# Railpack's PHP provider start command, overridden.
#
# Railpack starts every PHP service with `/start-container.sh`. When this file
# exists in the build context (the service's Root Directory, `src/`), Railpack
# uses it *instead of* its own — not in addition to it. So everything the
# default script did has to be reproduced here; the only addition is
# `filament:assets`.
#
# Why the addition is needed: Railpack runs
# `composer install --optimize-autoloader --no-scripts`, and `--no-scripts`
# means `composer.json`'s `post-autoload-dump` hook — which is where
# `filament:upgrade` (and therefore `filament:assets`) lives — never runs
# during the build. The result is a panel whose CSS and JS 404 while the app
# itself works fine: `/css/filament/filament/app.css` was returning 404 on the
# beta and `public/css/filament/` did not exist in the container at all.
#
# Publishing at container start rather than committing the files is forced by
# `src/.gitignore`, which ignores `/public/css` and `/public/js` outright —
# those directories are generated artefacts here, not source.
#
# See ADR-0024 (`docs/adr/0024-railpack-over-custom-image.md`), which predicted
# this exact gap, and `docs/how-to/troubleshooting/infra-and-environment.md`.

set -e

if [ "$IS_LARAVEL" = "true" ]; then
  if [ "$RAILPACK_SKIP_MIGRATIONS" != "true" ]; then
    # Kept from Railpack's default. This project sets
    # RAILPACK_SKIP_MIGRATIONS=true deliberately, so migrations run in the
    # service's preDeployCommand instead — a bad migration fails the deploy
    # rather than crash-looping the running container (ADR-0023/0024).
    echo "Running migrations and seeding database ..."
    php artisan migrate --force
  fi

  # The one line that is not Railpack's. Must come before `optimize`, so the
  # published assets are in place before anything caches a view referencing
  # them.
  echo "Publishing Filament assets ..."
  php artisan filament:assets

  php artisan storage:link
  php artisan optimize:clear
  php artisan optimize

  echo "Starting Laravel server ..."
fi

# Start the FrankenPHP server. Reproduced verbatim from Railpack's default
# script: Caddy binds {$PORT} on all interfaces, IPv6 included, which is what
# Railway's proxy reaches containers over.
docker-php-entrypoint --config /Caddyfile --adapter caddyfile 2>&1
