# PHP-FPM image for local development. Not used in production — Forge
# provisions PHP-FPM directly on the VPS.
#
# 8.4: composer.json now requires "^8.4" (symfony/* v8.1.x, solved into
# composer.lock, requires PHP >=8.4.1 — the constraint was raised to match
# what the lockfile already governs).
#
# Two build targets:
#   base    — the php-fpm image everything except the browser suite uses.
#   browser — base + Node 22 + Playwright/Chromium, for the Pest browser
#             testsuite only (ADR-0017). Built and run on demand via the
#             `playwright` compose service; never part of the default stack.
FROM php:8.4-fpm AS base

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    libicu-dev \
    gnupg \
    && rm -rf /var/lib/apt/lists/*

# Lets `pest --dirty` / `--tia` (Pest 5, ADR-0018) actually find a git
# repository, system-wide and surviving every container recreate — unlike
# `git config --global`, which writes to $HOME and is wiped whenever the
# container is rebuilt. Two settings, both needed:
#   - safe.directory: a bind-mounted directory is owned by the host user's
#     uid, which this image's root user does not recognise as "its own,"
#     and git refuses to operate in a repository it does not trust by
#     default (CVE-2022-24765's fix). `*` trusts every directory rather
#     than naming /var/www/repo specifically, because the exact mount
#     path is a compose-file detail this Dockerfile should not have to
#     track — the container has no untrusted multi-tenant use where that
#     matters.
#   - GIT_DISCOVERY_ACROSS_FILESYSTEM: a bind mount can present as a
#     different filesystem (different st_dev) than its parent directory
#     even though both live under the same host path, and git's default
#     "stop at the first filesystem boundary" walk-up refuses to cross
#     that even when .git is right there. See docker-compose.yml's own
#     comment on the /var/www/repo mount for the full reasoning; both
#     settings together are what makes `cd /var/www/repo/src && pest
#     --dirty` work at all.
RUN git config --system --add safe.directory '*'
ENV GIT_DISCOVERY_ACROSS_FILESYSTEM=1

# Oracle's mysql-client, not Debian's `default-mysql-client` — on Debian that
# package is MariaDB's client, which rejects the MySQL-only flags Laravel passes
# to mysqldump (--column-statistics=0, --set-gtid-purged=OFF). `schema:dump`
# fails against it, and without schema:dump every `migrate:fresh` replays all
# migrations: roughly four minutes on this stack, paid again by every test run.
#
# Two things that will look wrong and are not:
#   - RPM-GPG-KEY-mysql-2025, not -2023. The 2023 key expired 2025-10-22, and
#     apt rejects the repository outright once it has.
#   - `bookworm`, while this image is Debian trixie. MySQL's trixie suite
#     exists but ships no mysql-8.0 component yet; the bookworm build installs
#     and runs fine here. Move to trixie once the component appears.
RUN curl -fsSL https://repo.mysql.com/RPM-GPG-KEY-mysql-2025 \
        | gpg --dearmor -o /usr/share/keyrings/mysql.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/mysql.gpg] http://repo.mysql.com/apt/debian/ bookworm mysql-8.0" \
        > /etc/apt/sources.list.d/mysql.list \
    && apt-get update && apt-get install -y --no-install-recommends mysql-client \
    && rm -rf /var/lib/apt/lists/*

# `sockets` is required by pestphp/pest-plugin-browser (ADR-0017) — the Pest 5
# browser suite talks to the Playwright process over a socket. Every other
# extension here is a framework/runtime baseline.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
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
        sockets \
    && rm -rf /var/lib/apt/lists/*

# PCOV for `pest --coverage` — line coverage only, not Xdebug's fuller (and
# slower) instrumentation. Chosen because tests/Concurrency/ already pays
# real per-test process-boot overhead; ADR-0009 has the full reasoning,
# including why this is a report, not a CI gate.
RUN pecl install pcov \
    && docker-php-ext-enable pcov
COPY docker/php/conf.d/pcov.ini /usr/local/etc/php/conf.d/pcov.ini
COPY docker/php/conf.d/cli-memory.ini /usr/local/etc/php/conf.d/cli-memory.ini
COPY docker/php/conf.d/security.ini /usr/local/etc/php/conf.d/security.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# No non-root USER here, deliberately: this image is dev/CLI convenience
# (composer, artisan, one-off `docker compose run` commands) as well as
# the php-fpm daemon. Running the image as root and letting php-fpm's own
# pool config (www.conf: user = www-data) drop privilege for the actual
# request-handling workers is what Sail does too — a container USER
# directive here only fights named-volume ownership for no security gain
# in a local dev environment.
EXPOSE 9000
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# browser — base plus Node 22 and Playwright's Chromium, for the Pest 5
# browser testsuite (`tests/Browser/`, ADR-0017). pest-plugin-browser spawns
# `./node_modules/.bin/playwright run-server` as a child of the PHP process,
# so Node, the Playwright npm package, and the browser binaries all have to
# live in the same image that runs Pest — the plugin has no remote-server
# option.
#
# This target is only ever built by the `playwright` compose service (and
# the CI browser job). The default stack builds `base`.
# ---------------------------------------------------------------------------
FROM base AS browser

# Node 22 from NodeSource — Debian trixie's own nodejs is too old for the
# Playwright CLI. `--with-deps` on `playwright install` pulls the system
# libraries Chromium needs (libnss3, libatk, fonts, …); pinning the browser
# path into the image keeps the download in a layer, not the node_modules
# volume. The `playwright` npm package itself is a devDependency in
# package.json — pest-plugin-browser calls ./node_modules/.bin/playwright,
# so it must be volume-installed alongside Vite's deps, not global here.
ENV PLAYWRIGHT_BROWSERS_PATH=/opt/ms-playwright
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/* \
    && npx --yes playwright@1.62.1 install --with-deps chromium

CMD ["sleep", "infinity"]
