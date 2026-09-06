# PHP-FPM image for local development. Not used in production — Forge
# provisions PHP-FPM directly on the VPS.
#
# 8.4, not 8.3: composer.json declares "^8.3" but composer.lock was solved
# against symfony/* v8.1.x, which requires PHP >=8.4.1. The lockfile is what
# actually governs `composer install`, so the container follows it. This
# mismatch needs resolving with the team — see README/PR notes.
FROM php:8.4-fpm

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
