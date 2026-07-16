#!/usr/bin/env bash
set -euo pipefail

COMMAND="${1:-prepare}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
APP_ROOT="$(cd "$SCRIPT_DIR/../web" && pwd -P)"

case "$COMMAND" in
    prepare)
        cd "$APP_ROOT"
        composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
        npm ci
        npm run build
        php artisan config:cache
        php artisan route:cache
        php artisan view:cache
        php artisan migrate --force
        ;;
    status)
        cd "$APP_ROOT"
        php artisan about --only=environment,cache,drivers
        php artisan migrate:status --no-interaction
        ;;
    *)
        echo "Usage: $0 [prepare|status]" >&2
        exit 64
        ;;
esac
