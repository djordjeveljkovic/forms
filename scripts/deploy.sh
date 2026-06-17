#!/usr/bin/env bash
# =============================================================================
# deploy.sh — Run on every deploy.
#
# Use this from your CI/CD, or run it manually:
#   sudo -u forms ./scripts/deploy.sh
#
# Steps:
#   1. git pull
#   2. rebuild the app image (no cache)
#   3. bring up the stack (recreates only what changed)
#   4. run pending migrations
#   5. optimise caches (config:cache, route:cache, view:cache, event:cache)
#   6. report the running version
# =============================================================================
set -euo pipefail

PROJECT_DIR="${PROJECT_DIR:-/opt/forms}"
cd "$PROJECT_DIR"

# Where to write the current deploy info (useful for rollback).
DEPLOY_LOG="${PROJECT_DIR}/storage/logs/deploys.log"
mkdir -p "$(dirname "$DEPLOY_LOG")"

COMMIT="$(git rev-parse --short HEAD)"
TIMESTAMP="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
log() { echo "[$TIMESTAMP] [$COMMIT] $*" | tee -a "$DEPLOY_LOG"; }

log "Deploy started"

# ---- 1. Pull the latest code ----
log "git fetch + reset"
git fetch --tags --prune
git reset --hard "origin/$(git symbolic-ref --short HEAD)"

# ---- 2. Rebuild the app image ----
log "Building app image (no cache)"
docker compose build --no-cache app

# ---- 3. Bring up the stack (zero-downtime for HTTP; queue restarts) ----
log "Recreating app container"
docker compose up -d --no-deps --force-recreate app

# ---- 4. Run pending migrations ----
log "Running pending migrations"
docker compose exec -T app php artisan migrate --force --no-interaction

# ---- 5. Optimise caches for production ----
log "Caching config/routes/views"
docker compose exec -T app php artisan config:cache
docker compose exec -T app php artisan route:cache
docker compose exec -T app php artisan view:cache
docker compose exec -T app php artisan event:cache

# ---- 6. Show the running version ----
log "Final state"
docker compose ps
docker compose exec -T app php artisan --version

log "Deploy complete"
