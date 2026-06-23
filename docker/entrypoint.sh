#!/bin/sh
# Entrypoint wrapper used by docker-compose to handle first-boot bootstrap
# without baking migrations into the image.
#
# Behaviour:
#   - First run (no APP_KEY)            -> refuse to start; user must run
#                                          `make key` to generate one.
#   - First run (no `migrations` table)  -> run `php artisan migrate --force`
#                                          so the user doesn't have to.
#   - Every run (storage empty)         -> fix ownership + permissions.
#   - Every run (caches present)        -> blow them away so they don't
#                                          ship stale state.
#
# After the bootstrap, supervisord is exec'd and takes over.

set -eu

cd /var/www/html

# -- Permissions -----------------------------------------------------------
mkdir -p \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    storage/app/public
chown -R www-data:www-data storage bootstrap/cache || true
find storage bootstrap/cache -type d -exec chmod 0775 {} \; 2>/dev/null || true
find storage bootstrap/cache -type f -exec chmod 0664 {} \; 2>/dev/null || true

# -- APP_KEY guard ---------------------------------------------------------
if [ -z "${APP_KEY:-}" ]; then
    echo "FATAL: APP_KEY is empty." >&2
    echo "  Generate one with: make -f Makefile.docker key" >&2
    exit 1
fi

# -- Database bootstrap (only on first run) -------------------------------
need_migrate=0
if [ "${DB_CONNECTION:-mysql}" != "sqlite" ]; then
    # Wait for MySQL to accept connections (up to 180s — mysql:8.4 first
    # boot has to run initdb on the persisted volume, which can easily
    # blow past 60s on slow disks).
    echo "Waiting for database ${DB_HOST:-mysql}:${DB_PORT:-3306}…"
    for i in $(seq 1 180); do
        if php -r "try { new PDO('mysql:host=${DB_HOST:-mysql};port=${DB_PORT:-3306}', '${DB_USERNAME:-forms}', '${DB_PASSWORD:-forms}'); exit(0); } catch (Throwable \$e) { exit(1); }" 2>/dev/null; then
            echo "  ✓ database is up"
            break
        fi
        sleep 1
    done
fi

# Detect a brand-new schema by looking for the `migrations` table.
if ! php artisan migrate:status >/dev/null 2>&1; then
    need_migrate=1
fi

if [ "$need_migrate" = "1" ]; then
    echo "Running first-time database migrations…"
    php artisan migrate --force --no-interaction
    php artisan storage:link || true
fi

# -- Cache hygiene ---------------------------------------------------------
# Never ship a stale config or route cache from the image.
rm -f bootstrap/cache/config.php bootstrap/cache/routes-v7.php 2>/dev/null || true

# -- Hand off to supervisord -----------------------------------------------
echo "Starting supervisord (nginx + php-fpm + queue workers + scheduler)…"
exec /usr/bin/supervisord \
    --configuration /etc/supervisor/supervisord.conf \
    --nodaemon
