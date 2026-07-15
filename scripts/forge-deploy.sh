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

# Long-lived processes otherwise continue executing the previous release after
# the current code is updated. Signal an existing Realtime sideband process to
# release its durable leases, then immediately launch the owner-test process for
# this release. The lease ledger makes a brief overlap safe.
php artisan voice:realtime-sidebands-restart || true
php artisan queue:restart
php artisan schedule:interrupt || true

VOICE_SIDEBAND_PID_FILE="storage/framework/voice-realtime-sidebands.pid"
VOICE_SIDEBAND_LOG="storage/logs/voice-realtime-sidebands.log"
VOICE_WORKER_PID_FILE="storage/framework/voice-realtime-workers.pid"
VOICE_WORKER_LOG="storage/logs/voice-realtime-workers.log"
nohup php artisan voice:realtime-sidebands --no-interaction >> "$VOICE_SIDEBAND_LOG" 2>&1 </dev/null &
VOICE_SIDEBAND_PID=$!
echo "$VOICE_SIDEBAND_PID" > "$VOICE_SIDEBAND_PID_FILE"

# Forge's existing default-queue workers do not consume the dedicated
# voice-high queue unless their process command explicitly names it. Launch
# the three warm owner-test workers required by the voice contract so a live
# deploy cannot accept audio and then leave typed operations unconsumed.
VOICE_WORKER_PIDS=()
: > "$VOICE_WORKER_PID_FILE"
for slot in 1 2 3; do
    nohup php artisan queue:work --queue=voice-high --sleep=1 --tries=1 --timeout=180 --no-interaction \
        >> "$VOICE_WORKER_LOG" 2>&1 </dev/null &
    VOICE_WORKER_PIDS+=("$!")
    echo "$!" >> "$VOICE_WORKER_PID_FILE"
done

# Fail the deployment instead of leaving Browser Voice visibly enabled with no
# server owner for the OpenAI Realtime sideband.
sleep 1
if ! kill -0 "$VOICE_SIDEBAND_PID" 2>/dev/null; then
    echo "Bean Realtime sideband failed to start; inspect $APP_ROOT/$VOICE_SIDEBAND_LOG" >&2
    exit 1
fi
for worker_pid in "${VOICE_WORKER_PIDS[@]}"; do
    if ! kill -0 "$worker_pid" 2>/dev/null; then
        echo "Bean voice-high worker failed to start; inspect $APP_ROOT/$VOICE_WORKER_LOG" >&2
        exit 1
    fi
done
