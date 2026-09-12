# Production/beta image (ADR-0023). At the repository root deliberately:
# Railway and Railpack both autodetect a root-level Dockerfile with no
# dashboard configuration, and the one setting nobody has to remember is the
# one that cannot be set wrong.
#
# This is NOT docker/php/Dockerfile. That file's own header says "for local
# development. Not used in production," and it means it — it carries pcov,
# mysql-client, Playwright/Chromium, and `git config --system safe.directory
# '*'`, all of which are test-harness concerns. Shipping it would put a
# coverage profiler and a browser engine on a public host.
#
# Build context is the repository root, because the app lives in src/ while
# docker/production/ sits beside it. Every COPY below is therefore prefixed
# src/ or docker/.
#
#   Stage 1 (assets)  — Node builds Vite's bundles. Discarded afterwards.
#   Stage 2 (vendor)  — Composer resolves PHP deps with --no-dev.
#   Stage 3 (runtime) — php-fpm + nginx + supervisord, and nothing else.

# ---------------------------------------------------------------------------
# Stage 1 — front-end assets
# ---------------------------------------------------------------------------
FROM node:22-alpine AS assets

WORKDIR /app

# package.json first, so a source-only change does not re-resolve npm.
COPY src/package.json src/package-lock.json ./
# `npm ci` needs the lockfile to match package.json exactly; it is committed.
RUN npm ci --ignore-scripts

# vite.config.js reads resources/ and writes public/build. tailwind scans the
# Blade templates for class names, so resources/views has to be present at
# build time or the stylesheet comes out missing every utility the templates
# use — a page that renders unstyled rather than erroring, which is the
# failure mode ResponsiveTest's own Tailwind probe exists to catch.
COPY src/vite.config.js ./
COPY src/resources ./resources
COPY src/public ./public

RUN npm run build

# ---------------------------------------------------------------------------
# Stage 2 — PHP dependencies
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

COPY src/composer.json src/composer.lock ./

# --no-dev drops Pest, Larastan, Pint, Blueprint and the Faker stack.
# --no-scripts because post-autoload-dump runs `artisan package:discover` and
# `filament:upgrade`, and artisan cannot boot before the application code is
# copied in. The runtime stage runs them once everything is in place.
# --ignore-platform-req=ext-pcov: pcov is a dev-only extension this image
# deliberately omits, and composer.lock records it as present in dev.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader

# ---------------------------------------------------------------------------
# Stage 3 — runtime
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-alpine AS runtime

# nginx and supervisor from Alpine's repos; the rest are build deps for the
# PHP extensions and are removed in the same layer so they never reach the
# final image.
#
# Extension set mirrors docker/php/Dockerfile's MINUS the test-only ones:
# no pcov (coverage), no sockets (pest-plugin-browser talks to Playwright
# over one — there is no browser suite here). bcmath is NOT optional: every
# money value in this application is computed with it (ADR-0001's
# "money as decimal/bcmath, never float"), so an image without it fails at
# the first cart total rather than at boot.
RUN apk add --no-cache \
        nginx \
        supervisor \
        gettext \
        libpng \
        libjpeg-turbo \
        freetype \
        libzip \
        icu-libs \
        oniguruma \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        libzip-dev \
        icu-dev \
        oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        intl \
        opcache \
    && apk del .build-deps

# opcache tuned for a deployed image: the code never changes between
# restarts, so revalidation is pure overhead. validate_timestamps=0 is what
# makes that explicit — it also means a file edited inside a running
# container has no effect, which is correct here and worth knowing before
# anyone tries it (ADR-0023 records that editing in place is not a workflow
# this target supports).
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.interned_strings_buffer=16'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# SEC-004's other half: suppress the PHP version banner. docker/php/conf.d/
# security.ini does this for the dev image and says in its own comment that
# production is owed it independently — this is that.
RUN echo 'expose_php = Off' > /usr/local/etc/php/conf.d/security.ini

# php-fpm listens on loopback for nginx in the same container, and logs to
# stderr so Railway captures it. clear_env=no lets the pool see the
# container's environment variables — without it every Railway-injected
# variable (DB_*, APP_KEY, STRIPE_*) is invisible to PHP, which presents as
# a database connection refused against 127.0.0.1.
RUN { \
        echo '[global]'; \
        echo 'error_log = /dev/stderr'; \
        echo 'daemonize = no'; \
        echo '[www]'; \
        echo 'listen = 127.0.0.1:9000'; \
        echo 'clear_env = no'; \
        echo 'catch_workers_output = yes'; \
        echo 'decorate_workers_output = no'; \
    } > /usr/local/etc/php-fpm.d/zz-docker.conf

WORKDIR /var/www/html

# Application code, then the resolved dependencies and built assets on top.
COPY src/ ./
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

# The composer scripts skipped in stage 2, now that artisan can boot.
RUN composer dump-autoload --no-dev --optimize \
    && php artisan package:discover --ansi \
    && php artisan filament:upgrade

# nginx.conf is a template: ${PORT} is substituted at start time, not here.
COPY docker/production/nginx.conf /etc/nginx/http.d/default.conf.template
COPY docker/production/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# storage/ and bootstrap/cache must be writable by php-fpm's www-data worker.
# The demo images committed under storage/app/public ship inside the image,
# which is what makes them survive a redeploy without a volume — see
# ADR-0023 on why a volume is optional for a demo box and required the moment
# anyone uploads something they intend to keep.
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Entrypoint: substitute PORT into the nginx config, then hand off to
# supervisord. Written here rather than as a separate committed script
# because it is four lines and splitting it across two files hides the
# ordering that matters.
#
# PORT defaults to 8080 for a plain `docker run` with no platform injecting
# one. Railway always sets it.
#
# `storage:link` runs at start, not build: it creates public/storage ->
# storage/app/public, and if a volume is ever mounted over storage/app/public
# a build-time symlink would be shadowed by the mount. The link itself is
# gitignored (src/.gitignore lists /public/storage) and excluded from the
# build context, so nothing in the image pre-creates it — `--force` is
# belt-and-braces for a restart where the filesystem layer persisted it,
# not a workaround for a committed file.
RUN printf '%s\n' \
        '#!/bin/sh' \
        'set -e' \
        ': "${PORT:=8080}"' \
        'export PORT' \
        'envsubst "\$PORT" < /etc/nginx/http.d/default.conf.template > /etc/nginx/http.d/default.conf' \
        'php artisan storage:link --force' \
        'exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf' \
        > /usr/local/bin/docker-entrypoint.sh \
    && chmod +x /usr/local/bin/docker-entrypoint.sh

# No EXPOSE: the port is dynamic and Railway reads $PORT rather than the
# image's metadata.
#
# Deliberately NOT in the entrypoint: `migrate --force` and the config/route/
# view caches. Both belong in the Railway service's own start command, so
# that the queue worker and scheduler services — built from this same image —
# do not each race to run migrations on deploy. ADR-0023 has the exact
# commands per service.
CMD ["/usr/local/bin/docker-entrypoint.sh"]
