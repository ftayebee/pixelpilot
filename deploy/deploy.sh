#!/usr/bin/env bash
# Deploys the current main branch on a server that already has the app
# checked out at $APP_DIR with a production .env. Safe to re-run.
# Also usable as the Laravel Forge deploy script (Forge sets FORGE_PHP etc.).
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/pixelpilot}"
BRANCH="${BRANCH:-main}"
PHP="${FORGE_PHP:-php}"
COMPOSER="${FORGE_COMPOSER:-composer}"

cd "$APP_DIR"

echo "→ Fetching $BRANCH"
git fetch --prune origin "$BRANCH"
git reset --hard "origin/$BRANCH"

echo "→ PHP dependencies"
$COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

echo "→ Frontend build"
npm ci --no-audit --no-fund
npm run build

$PHP artisan down --retry=15 || true

echo "→ Migrations (never fresh/reset in production)"
$PHP artisan migrate --force

echo "→ Caches"
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache
$PHP artisan event:cache
$PHP artisan storage:link 2>/dev/null || true

$PHP artisan up

echo "→ Restarting queue workers"
$PHP artisan queue:restart

if command -v sudo >/dev/null && sudo -n true 2>/dev/null; then
    sudo -n systemctl reload php8.3-fpm || true
fi

echo "→ Health"
$PHP artisan about --only=environment

echo "Deployed $(git rev-parse --short HEAD)"
