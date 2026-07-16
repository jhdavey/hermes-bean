#!/usr/bin/env bash
set -euo pipefail

# Forge creates and activates zero-downtime releases. This repository command
# prepares that exact release, then owns the single-owner development runtime
# handoff only after Forge's `current` symlink points at it.

COMMAND="${1:-}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd -P)"
APP_ROOT="$REPO_ROOT/web"
SITE_PATH="${FORGE_SITE_PATH:-}"
SITE_ROOT_INPUT="${FORGE_SITE_ROOT:-}"

# A manual SSH recovery has no Forge environment. Infer the stable site paths
# from Forge's `<site>/releases/<id>` layout instead of putting runtime state in
# the disposable releases directory. Keeping `current` as SITE_PATH also makes
# activation from a non-current candidate fail closed.
if [ -z "$SITE_PATH" ]; then
    RELEASES_ROOT="$(dirname "$REPO_ROOT")"
    if [ "$(basename "$RELEASES_ROOT")" = releases ]; then
        INFERRED_SITE_ROOT="$(dirname "$RELEASES_ROOT")"
        SITE_PATH="$INFERRED_SITE_ROOT/current"
        [ -n "$SITE_ROOT_INPUT" ] || SITE_ROOT_INPUT="$INFERRED_SITE_ROOT"
    else
        SITE_PATH="$REPO_ROOT"
    fi
fi
[ -n "$SITE_ROOT_INPUT" ] || SITE_ROOT_INPUT="$(dirname "$SITE_PATH")"
SITE_ROOT="$(cd "$SITE_ROOT_INPUT" && pwd -P)"
RUNTIME_ROOT="${BEAN_VOICE_RUNTIME_ROOT:-$SITE_ROOT/.bean-voice-runtime}"
PHP_BIN="${FORGE_PHP:-php}"
COMPOSER_BIN="${FORGE_COMPOSER:-composer}"
INSPECTOR="${BEAN_VOICE_PROCESS_INSPECTOR:-}"
STARTUP_TIMEOUT="${BEAN_VOICE_STARTUP_TIMEOUT_SECONDS:-5}"
HANDOFF_TIMEOUT="${BEAN_VOICE_HANDOFF_TIMEOUT_SECONDS:-5}"
TERM_GRACE_TIMEOUT="${BEAN_VOICE_TERM_GRACE_TIMEOUT_SECONDS:-5}"
POLL_INTERVAL="${BEAN_VOICE_HEALTH_INTERVAL_SECONDS:-0.2}"
VOICE_WORKER_COUNT=3

case "$TERM_GRACE_TIMEOUT" in ''|*[!0-9]*|0) TERM_GRACE_TIMEOUT=5 ;; esac

STATE_DIR="$RUNTIME_ROOT/state"
LOG_DIR="$RUNTIME_ROOT/logs"
SIDEBAND_FILE="$STATE_DIR/sideband.pid"
WORKERS_FILE="$STATE_DIR/workers.pid"
RELEASE_FILE="$STATE_DIR/release"
COMMIT_FILE="$STATE_DIR/commit"
LOCK_DIR="$RUNTIME_ROOT/activation.lock"

fail() {
    echo "Bean voice deployment error: $*" >&2
    exit 1
}

physical_directory() {
    (cd "$1" 2>/dev/null && pwd -P)
}

assert_context() {
    local phase="$1"
    local expected=''
    local active=''

    [ -x "$APP_ROOT/artisan" ] || fail "missing executable Laravel app at $APP_ROOT/artisan"
    if [ -n "${FORGE_RELEASE_DIRECTORY:-}" ]; then
        expected="$(physical_directory "$FORGE_RELEASE_DIRECTORY")" \
            || fail "Forge release directory does not exist: $FORGE_RELEASE_DIRECTORY"
        [ "$REPO_ROOT" = "$expected" ] \
            || fail "$phase ran from $REPO_ROOT, expected Forge release $expected"
    fi
    if [ "$phase" = activate ]; then
        active="$(physical_directory "$SITE_PATH")" \
            || fail "Forge current path does not exist: $SITE_PATH"
        [ "$REPO_ROOT" = "$active" ] \
            || fail "activate must run after \$ACTIVATE_RELEASE(); current resolves to $active, not $REPO_ROOT"
    fi
}

role_matches() {
    local role="$1"
    local command_line="$2"
    case "$role:$command_line" in
        sideband:*"artisan voice:realtime-sidebands "*) return 0 ;;
        worker:*"artisan queue:work "*"--queue=voice-high"*) return 0 ;;
        *) return 1 ;;
    esac
}

inspect() {
    local operation="$1"
    shift
    if [ -n "$INSPECTOR" ]; then
        "$INSPECTOR" "$operation" "$@"
        return
    fi

    case "$operation" in
        alive) kill -0 "$1" 2>/dev/null ;;
        cwd) [ -e "/proc/$1/cwd" ] && readlink -f "/proc/$1/cwd" ;;
        command) [ -r "/proc/$1/cmdline" ] && tr '\0' ' ' < "/proc/$1/cmdline" ;;
        list)
            local role="$1"
            local proc pid command_line
            for proc in /proc/[0-9]*; do
                [ -r "$proc/cmdline" ] || continue
                pid="${proc##*/}"
                command_line="$(tr '\0' ' ' < "$proc/cmdline" 2>/dev/null || true)"
                role_matches "$role" "$command_line" && printf '%s\n' "$pid"
            done
            ;;
        *) return 64 ;;
    esac
}

process_is_current() {
    local pid="$1"
    local role="$2"
    local cwd=''
    local command_line=''
    case "$pid" in ''|*[!0-9]*) return 1 ;; esac
    inspect alive "$pid" || return 1
    cwd="$(inspect cwd "$pid" 2>/dev/null)" || return 1
    [ "$cwd" = "$APP_ROOT" ] || return 1
    command_line="$(inspect command "$pid" 2>/dev/null)" || return 1
    role_matches "$role" "$command_line"
}

read_pid() {
    local file="$1"
    local pid=''
    [ -f "$file" ] || return 1
    IFS= read -r pid < "$file" || true
    case "$pid" in ''|*[!0-9]*) return 1 ;; esac
    printf '%s\n' "$pid"
}

read_workers() {
    local pid
    [ -f "$WORKERS_FILE" ] || return 1
    while IFS= read -r pid; do
        case "$pid" in ''|*[!0-9]*) continue ;; esac
        printf '%s\n' "$pid"
    done < "$WORKERS_FILE"
}

generation_is_healthy() {
    local recorded_release=''
    local sideband_pid=''
    local worker_pid
    local count=0

    [ -f "$RELEASE_FILE" ] || return 1
    IFS= read -r recorded_release < "$RELEASE_FILE" || true
    [ "$recorded_release" = "$REPO_ROOT" ] || return 1
    sideband_pid="$(read_pid "$SIDEBAND_FILE")" || return 1
    process_is_current "$sideband_pid" sideband || return 1
    while IFS= read -r worker_pid; do
        process_is_current "$worker_pid" worker || return 1
        count=$((count + 1))
    done < <(read_workers)
    [ "$count" -eq "$VOICE_WORKER_COUNT" ]
}

stale_sidebands() {
    local active_pid="$1"
    local pid cwd
    while IFS= read -r pid; do
        [ -n "$pid" ] && [ "$pid" != "$active_pid" ] || continue
        cwd="$(inspect cwd "$pid" 2>/dev/null || true)"
        case "$cwd" in "$SITE_ROOT"/*) printf '%s\n' "$pid" ;; esac
    done < <(inspect list sideband 2>/dev/null || true)
}

wait_until() {
    local check="$1"
    local timeout="$2"
    shift 2
    local deadline=$((SECONDS + timeout))
    while [ "$SECONDS" -le "$deadline" ]; do
        "$check" "$@" && return 0
        sleep "$POLL_INTERVAL"
    done
    return 1
}

new_generation_is_healthy() {
    local sideband_pid="$1"
    shift
    local pid
    process_is_current "$sideband_pid" sideband || return 1
    for pid in "$@"; do process_is_current "$pid" worker || return 1; done
}

no_stale_sidebands() {
    [ -z "$(stale_sidebands "$1")" ]
}

retire_stale_sidebands() {
    local active_pid="$1"
    local pid

    wait_until no_stale_sidebands "$HANDOFF_TIMEOUT" "$active_pid" && return 0
    while IFS= read -r pid; do
        [ -n "$pid" ] && terminate_process "$pid" sideband
    done < <(stale_sidebands "$active_pid")
    wait_until no_stale_sidebands "$TERM_GRACE_TIMEOUT" "$active_pid"
}

acquire_lock() {
    mkdir -p "$RUNTIME_ROOT"
    if ! mkdir "$LOCK_DIR" 2>/dev/null; then
        local owner=''
        [ -f "$LOCK_DIR/pid" ] && IFS= read -r owner < "$LOCK_DIR/pid" || true
        if [ -n "$owner" ] && inspect alive "$owner"; then
            fail "another voice activation is running with PID $owner"
        fi
        rm -f "$LOCK_DIR/pid"
        rmdir "$LOCK_DIR" 2>/dev/null || fail "could not recover stale activation lock"
        mkdir "$LOCK_DIR" || fail "could not acquire activation lock"
    fi
    printf '%s\n' "$$" > "$LOCK_DIR/pid"
}

release_lock() {
    rm -f "$LOCK_DIR/pid"
    rmdir "$LOCK_DIR" 2>/dev/null || true
}

write_state() {
    local destination="$1"
    shift
    local temporary="$destination.$$"
    printf '%s\n' "$@" > "$temporary"
    mv "$temporary" "$destination"
}

start_process() {
    local role="$1"
    if [ "$role" = sideband ]; then
        (cd "$APP_ROOT" && exec nohup "$PHP_BIN" "$APP_ROOT/artisan" \
            voice:realtime-sidebands --no-interaction) \
            >> "$LOG_DIR/sideband.log" 2>&1 </dev/null &
    else
        (cd "$APP_ROOT" && exec nohup "$PHP_BIN" "$APP_ROOT/artisan" queue:work \
            --queue=voice-high --sleep=1 --tries=1 --timeout=180 --no-interaction) \
            >> "$LOG_DIR/workers.log" 2>&1 </dev/null &
    fi
    STARTED_PID=$!
}

terminate_process() {
    local pid="$1"
    local role="$2"
    local command_line=''
    inspect alive "$pid" || return 0
    command_line="$(inspect command "$pid" 2>/dev/null || true)"
    role_matches "$role" "$command_line" && kill -TERM "$pid" 2>/dev/null || true
}

prepare_release() {
    assert_context prepare
    cd "$APP_ROOT"
    rm -f bootstrap/cache/*.php
    "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction
    npm ci --ignore-scripts
    npm run build
    "$PHP_BIN" artisan migrate --force
    "$PHP_BIN" artisan config:clear
    "$PHP_BIN" artisan route:clear
    "$PHP_BIN" artisan view:clear
    "$PHP_BIN" artisan config:cache
    "$PHP_BIN" artisan route:cache
    "$PHP_BIN" artisan view:cache
    "$PHP_BIN" artisan storage:link >/dev/null 2>&1 || true
}

activate_release() {
    local sideband_pid
    local worker_pids=()
    local pid commit

    assert_context activate
    mkdir -p "$STATE_DIR" "$LOG_DIR"
    acquire_lock
    trap release_lock EXIT

    sideband_pid="$(read_pid "$SIDEBAND_FILE" 2>/dev/null || true)"
    if generation_is_healthy && no_stale_sidebands "$sideband_pid"; then
        echo "Bean voice runtime is already healthy on $REPO_ROOT."
        return
    fi

    cd "$APP_ROOT"
    "$PHP_BIN" artisan voice:realtime-sidebands-restart
    "$PHP_BIN" artisan queue:restart

    start_process sideband
    sideband_pid="$STARTED_PID"
    for ((slot = 1; slot <= VOICE_WORKER_COUNT; slot++)); do
        start_process worker
        worker_pids+=("$STARTED_PID")
    done

    if ! wait_until new_generation_is_healthy "$STARTUP_TIMEOUT" "$sideband_pid" "${worker_pids[@]}"; then
        terminate_process "$sideband_pid" sideband
        for pid in "${worker_pids[@]}"; do terminate_process "$pid" worker; done
        fail "new sideband or voice-high worker exited or started from the wrong release; inspect $LOG_DIR"
    fi

    if ! retire_stale_sidebands "$sideband_pid"; then
        terminate_process "$sideband_pid" sideband
        for pid in "${worker_pids[@]}"; do terminate_process "$pid" worker; done
        fail "a stale Realtime sideband survived graceful restart and bounded TERM handoff"
    fi

    commit="$(git -C "$REPO_ROOT" rev-parse HEAD 2>/dev/null || echo unknown)"
    write_state "$SIDEBAND_FILE" "$sideband_pid"
    write_state "$WORKERS_FILE" "${worker_pids[@]}"
    write_state "$RELEASE_FILE" "$REPO_ROOT"
    write_state "$COMMIT_FILE" "$commit"
    generation_is_healthy || fail "voice runtime failed its post-handoff health check"
    echo "Bean voice runtime active: release=$REPO_ROOT commit=$commit sideband=$sideband_pid workers=${worker_pids[*]}"
}

report_status() {
    local sideband_pid
    local workers=()
    local pid commit='unknown'
    assert_context activate
    generation_is_healthy || fail "voice runtime is missing, stale, or not running from $REPO_ROOT"
    sideband_pid="$(read_pid "$SIDEBAND_FILE")"
    no_stale_sidebands "$sideband_pid" || fail "a second or stale Realtime sideband is still running for $SITE_ROOT"
    while IFS= read -r pid; do workers+=("$pid"); done < <(read_workers)
    [ -f "$COMMIT_FILE" ] && IFS= read -r commit < "$COMMIT_FILE" || true
    echo "Bean voice runtime healthy: release=$REPO_ROOT commit=$commit sideband=$sideband_pid workers=${workers[*]}"
}

stop_runtime() {
    local sideband_pid pid
    assert_context activate
    mkdir -p "$STATE_DIR" "$LOG_DIR"
    acquire_lock
    trap release_lock EXIT
    cd "$APP_ROOT"
    "$PHP_BIN" artisan voice:realtime-sidebands-restart
    "$PHP_BIN" artisan queue:restart
    sideband_pid="$(read_pid "$SIDEBAND_FILE" 2>/dev/null || true)"
    [ -n "$sideband_pid" ] && terminate_process "$sideband_pid" sideband
    while IFS= read -r pid; do terminate_process "$pid" worker; done < <(read_workers 2>/dev/null || true)
    rm -f "$SIDEBAND_FILE" "$WORKERS_FILE" "$RELEASE_FILE" "$COMMIT_FILE"
    echo "Bean voice runtime stopped."
}

case "$COMMAND" in
    prepare) prepare_release ;;
    activate) activate_release ;;
    status) report_status ;;
    stop) stop_runtime ;;
    *) fail "usage: scripts/forge-deploy.sh prepare|activate|status|stop" ;;
esac
