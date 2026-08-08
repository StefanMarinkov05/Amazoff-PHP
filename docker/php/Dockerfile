# PHP-FPM image for local development. Not used in production — Forge
# provisions PHP-FPM directly on the VPS per ADR-0016.
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
    default-mysql-client \
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
    && rm -rf /var/lib/apt/lists/*

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
