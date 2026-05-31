#!/usr/bin/env bash
# Electrotech production deploy script
# Runs on the CyberPanel server, triggered by GitHub Actions on push to main

set -euo pipefail

log() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$PROJECT_ROOT"

log "Deploying from $PROJECT_ROOT"
log "Current commit (before pull): $(git rev-parse --short HEAD 2>/dev/null || echo 'n/a')"

log "Enabling maintenance mode"
php artisan down --refresh=15 --retry=60 || true

# Ensure cleanup on any failure: always bring the app back up.
cleanup() {
  log "Disabling maintenance mode"
  php artisan up || true
}
trap cleanup EXIT

log "Fetching latest code from origin/main"
git fetch --all --prune
git reset --hard origin/main

log "Installing Composer dependencies (production)"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

if [ -f package-lock.json ]; then
  log "Installing NPM dependencies"
  npm ci --no-audit --no-fund
  log "Building frontend assets"
  npm run build
fi

log "Running database migrations"
php artisan migrate --force

log "Syncing roles and permissions catalog"
php artisan db:seed --class=Database\\Seeders\\RoleAndPermissionSeeder --force

log "Clearing old caches"
php artisan optimize:clear
php artisan filament:clear-cached-components || true
php artisan icons:clear || true

log "Rebuilding production caches"
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan filament:cache-components || true
php artisan icons:cache || true
php artisan optimize

log "Ensuring storage symlink exists"
# --force overwrites a stale or wrongly-typed `public/storage` (e.g. a
# leftover directory from a previous misconfigured deploy). Without it,
# storage:link would silently fail and uploaded attachments would 404.
php artisan storage:link --force || true

log "Fixing writable permissions on storage and bootstrap/cache"
chmod -R ug+rwX storage bootstrap/cache || true

log "Restarting queue workers"
php artisan queue:restart || true

log "Deploy finished at commit: $(git rev-parse --short HEAD)"
