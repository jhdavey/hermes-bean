#!/usr/bin/env bash
set -euo pipefail

SITE_ROOT="/home/forge/heybean.org/current"
APP_ROOT="$SITE_ROOT/web"

cd "$SITE_ROOT"

git fetch origin main
git checkout -f -B main origin/main

cd "$APP_ROOT"

# Clear stale bootstrap caches before Composer/Artisan so old package discovery
# files cannot reference providers that are not installed in production.
rm -f bootstrap/cache/*.php

composer install --no-dev --optimize-autoloader --no-interaction
npm ci --ignore-scripts
npm run build

php artisan migrate --force
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link >/dev/null 2>&1 || true

# Long-lived queue and sub-minute scheduler processes otherwise continue
# executing the previous release after the current symlink/code is updated.
php artisan queue:restart
php artisan schedule:interrupt || true
