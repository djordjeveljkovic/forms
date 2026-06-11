# syntax=docker/dockerfile:1.6
# =============================================================================
# Production image for the Forms application.
#
# Pipeline:
#   1. base         — php:8.3-fpm-bookworm + system deps + PHP extensions
#   2. vendor       — composer install (PHP-only, produces /var/www/html)
#   3. node_assets  — copies vendor/ from (2) and runs `npm run build` so
#                     Tailwind/Flux @source directives can resolve
#                     ../../vendor/livewire/flux/... paths
#   4. app          — final runtime image: copies (2) + the built
#                     public/build/ from (3), installs supervisord + nginx
# =============================================================================

ARG PHP_VERSION=8.3
ARG NODE_VERSION=22

# -----------------------------------------------------------------------------
# 1. Base image — PHP 8.3 FPM with all extensions needed by Laravel + Flux
# -----------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-bookworm AS base

ENV DEBIAN_FRONTEND=noninteractive \
    LANG=en_US.UTF-8 \
    LANGUAGE=en_US:en \
    LC_ALL=en_US.UTF-8

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        apt-utils \
        bash \
        ca-certificates \
        curl \
        git \
        gnupg \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libwebp-dev \
        libzip-dev \
        libicu-dev \
        libonig-dev \
        libxml2-dev \
        libssl-dev \
        libcurl4-openssl-dev \
        libsqlite3-dev \
        libmemcached11 \
        libmemcachedutil2 \
        librabbitmq4 \
        libzip4 \
        supervisor \
        nginx-light \
        zip unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        bcmath \
        ctype \
        curl \
        dom \
        fileinfo \
        filter \
        hash \
        iconv \
        intl \
        mbstring \
        opcache \
        pdo_mysql \
        pdo_sqlite \
        session \
        tokenizer \
        xml \
        zip \
    && pecl install redis memcached \
    && docker-php-ext-enable redis memcached \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Composer is copied from the official image into every stage that needs it.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# -----------------------------------------------------------------------------
# 2. Vendor stage — PHP-only. Installs composer dependencies.
# -----------------------------------------------------------------------------
FROM base AS vendor
WORKDIR /var/www/html

# Copy composer files first so the install layer is cached.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader

# Now copy the rest of the application. The vendor/ directory is reused
# in the next stage, so we keep it around.
COPY . .
RUN composer dump-autoload --classmap-authoritative --no-dev

# -----------------------------------------------------------------------------
# 3. Node assets stage — runs `npm run build`.
#
# Tailwind's @source directives in app.css scan into ../../vendor/, and the
# @import statement pulls in flux.css from there too. We COPY vendor/ from
# the previous stage so those paths resolve, then run the build.
# -----------------------------------------------------------------------------
FROM node:${NODE_VERSION}-bookworm-slim AS node_assets
WORKDIR /app

# Copy composer files + the pre-installed vendor/ directory from the vendor
# stage. We can't `npm ci` until we have package.json, so the order is:
#   1. vendor/  (from vendor stage)
#   2. package files + node_modules (npm ci)
#   3. sources (resources/, public/ assets, vite.config.js)
#   4. build
COPY --from=vendor /var/www/html/vendor ./vendor

# Copy package files first to cache the npm install layer.
COPY package.json package-lock.json* bun.lock* ./
RUN if [ -f package-lock.json ]; then npm ci --no-audit --no-fund; \
    elif [ -f bun.lockb ]; then npm install -g bun && bun install --frozen-lockfile; \
    else npm install --no-audit --no-fund; fi

# Copy Vite config + resource sources for the production build.
COPY vite.config.js ./
COPY resources/ ./resources/

# Bring in only the public files that don't change between builds. We
# rebuild public/build/ from scratch below, so we exclude build/, hot
# and storage to avoid shipping stale dev artefacts.
RUN mkdir -p ./public
COPY public/index.php ./public/index.php
COPY public/.htaccess ./public/.htaccess
COPY public/favicon.ico ./public/favicon.ico
COPY public/favicon.svg ./public/favicon.svg
COPY public/apple-touch-icon.png ./public/apple-touch-icon.png
COPY public/robots.txt ./public/robots.txt

# Build. Now that vendor/ is in /app/vendor, the @source and @import
# directives in app.css can resolve into vendor/livewire/flux/dist/flux.css.
RUN npm run build

# -----------------------------------------------------------------------------
# 4. Final runtime image — nginx + php-fpm + workers + scheduler
# -----------------------------------------------------------------------------
FROM base AS app

WORKDIR /var/www/html

# Copy the application + composer dependencies from the vendor stage.
COPY --from=vendor /var/www/html /var/www/html

# Copy the built frontend assets from the node stage.
COPY --from=node_assets /app/public/build ./public/build

# Set up directory permissions for the www-data user.
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 0755 /var/www/html/storage /var/www/html/bootstrap/cache

# Opcache tuning for production.
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=0'; \
        echo 'opcache.memory_consumption=256'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.revalidate_freq=2'; \
        echo 'opcache.fast_shutdown=1'; \
        echo 'opcache.save_comments=1'; \
    } > /usr/local/etc/php/conf.d/00-opcache.ini

# PHP-FPM tuning.
RUN { \
        echo 'pm.max_children=40'; \
        echo 'pm.start_servers=8'; \
        echo 'pm.min_spare_servers=4'; \
        echo 'pm.max_spare_servers=16'; \
        echo 'pm.max_requests=500'; \
        echo 'pm.process_idle_timeout=10s'; \
        echo 'request_terminate_timeout=120s'; \
    } > /usr/local/etc/php-fpm.d/zz-app.conf

# Copy nginx config.
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

# Copy supervisor config (runs php-fpm + queue worker + scheduler).
COPY docker/supervisor/supervisord.conf /etc/supervisor/supervisord.conf

# Persistent storage dirs.
RUN mkdir -p /var/www/html/storage/logs /var/www/html/storage/framework/cache \
             /var/www/html/storage/framework/sessions /var/www/html/storage/framework/views \
             /var/www/html/storage/app/public \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Healthcheck: hit Laravel's /up endpoint.
RUN echo '#!/bin/sh\ncurl -fsS http://127.0.0.1/up >/dev/null 2>&1 || exit 1' \
    > /usr/local/bin/app-healthcheck \
    && chmod +x /usr/local/bin/app-healthcheck

# First-boot wrapper: waits for the database, runs migrations, then execs
# supervisord so the entrypoint stays a single process for docker stop.
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --retries=3 --start-period=60s \
    CMD app-healthcheck

# Entrypoint handles first-boot bootstrap (migrations, permissions, APP_KEY
# guard) and then hands off to supervisord.
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
