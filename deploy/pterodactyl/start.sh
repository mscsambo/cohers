#!/bin/bash
# =============================================================================
#  COHERS start script for a Pterodactyl container (no root, one process tree)
#
#  Start it with:   bash /home/container/cohers/start.sh   (e.g. from start-all.sh)
#
#  PHP: uses $PHP_BIN, else the container's php (8.2+ with the needed extensions),
#  else a PHP binary you placed at ./.php/php (e.g. a static PHP build). It never downloads anything.
#
#  Runs, from its own folder:
#    - first-run setup: .env, app key, SQLite database, reference + demo data
#    - web dashboard on the allocated port ($SERVER_PORT, e.g. 24063)
#    - queue worker (sends Telegram notifications), scheduler (overdue checks)
#    - Telegram long polling (webhooks need port 443/80/88/8443, not available here)
#  Background workers are restarted automatically if they stop.
# =============================================================================
cd "$(dirname "$0")" || exit 1
PORT="${COHERS_PORT:-${SERVER_PORT:-8000}}"

log() { printf '[cohers %s] %s\n' "$(date -u +%H:%M:%S)" "$*"; }

# --- PHP ------------------------------------------------------------------------
NEED_EXT='["pdo_sqlite","mbstring","openssl","curl","fileinfo","tokenizer","ctype","dom","xml"]'
php_ok() { # php_ok BINARY -> 0 if PHP >= 8.2 with all needed extensions
    { command -v "$1" || [[ -x "$1" ]]; } >/dev/null 2>&1 || return 1
    "$1" -r '$m=array_diff('"$NEED_EXT"', array_map("strtolower", get_loaded_extensions()));
             exit(version_compare(PHP_VERSION, "8.2.0", ">=") && !$m ? 0 : 1);' >/dev/null 2>&1
}

if [[ -n "${PHP_BIN:-}" ]]; then PHP="$PHP_BIN"
elif php_ok php; then PHP="php"
else PHP="$(pwd)/.php/php"; chmod +x "$PHP" 2>/dev/null
fi
if ! php_ok "$PHP"; then
    log "ERROR: no usable PHP 8.2+ found."
    log "  Either ask the panel admin for an image with PHP 8.2+, or upload a Linux x86_64 PHP binary"
    log "  (8.2+ with: pdo_sqlite mbstring openssl curl fileinfo tokenizer ctype dom xml) to: $(pwd)/.php/php"
    [[ -x "$PHP" ]] && log "  Found $PHP: $("$PHP" -r 'echo PHP_VERSION, " missing: ", implode(" ", array_diff('"$NEED_EXT"', array_map("strtolower", get_loaded_extensions())));' 2>&1)"
    exit 1
fi
APP="$("$PHP" -r 'echo str_replace(chr(92), "/", getcwd());')"   # the path as PHP sees it
log "PHP $("$PHP" -r 'echo PHP_VERSION;') OK ($PHP) · port $PORT"

mkdir -p storage/app/private storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache database

# --- first run -----------------------------------------------------------------
if [[ ! -f .env ]]; then
    cp .env.pterodactyl .env
    sed -i "s|^DB_DATABASE=.*|DB_DATABASE=$APP/database/database.sqlite|" .env
    [[ -n "${APP_URL:-}" ]] && sed -i "s|^APP_URL=.*|APP_URL=$APP_URL|" .env
    [[ -n "${TELEGRAM_BOT_TOKEN:-}" ]] && sed -i "s|^TELEGRAM_BOT_TOKEN=.*|TELEGRAM_BOT_TOKEN=$TELEGRAM_BOT_TOKEN|" .env
    "$PHP" artisan key:generate --force --no-interaction
    log "Created .env"
fi
[[ -f database/database.sqlite ]] || touch database/database.sqlite

"$PHP" artisan migrate --force --no-interaction || { log "ERROR: migration failed"; exit 1; }

# Checked on every start, not only when .env is new: if the database file was lost or
# replaced, this sets it up again and prints fresh passwords instead of leaving no login.
SETUP_ARGS=()
[[ "${SEED_DEMO:-1}" == "1" ]] && SETUP_ARGS+=(--demo)
[[ -n "${ADMIN_EMAIL:-}" ]] && SETUP_ARGS+=(--admin-email="$ADMIN_EMAIL")
"$PHP" artisan cohers:first-run "${SETUP_ARGS[@]}" --no-interaction || { log "ERROR: initial setup failed"; exit 1; }

"$PHP" artisan optimize:clear >/dev/null
"$PHP" artisan optimize >/dev/null

# --- background services (auto-restart) ---------------------------------------
keep_alive() { # keep_alive NAME RESTART_DELAY CMD...
    local name="$1" delay="$2"; shift 2
    ( while true; do
        "$@" >> "storage/logs/$name.log" 2>&1
        sleep "$delay"
      done ) &
}

keep_alive worker 5 "$PHP" artisan queue:work --sleep=3 --tries=5 --backoff=10 --max-time=3600
keep_alive scheduler 5 "$PHP" artisan schedule:work
# Exits at once while no token is configured (.env or Admin > Configuration); retried every 30s,
# so a token saved in the dashboard is picked up without restarting the server.
keep_alive telegram 30 "$PHP" artisan cohers:telegram-poll --force
log "Worker, scheduler and Telegram polling started (logs in storage/logs/)"

# Optional free HTTPS address for the Telegram Mini App: upload the Linux cloudflared
# program to .bin/cloudflared (see DEPLOY-PTERODACTYL.md). COHERS_TUNNEL=0 turns it off.
if [[ "${COHERS_TUNNEL:-1}" == "1" && -f "${CLOUDFLARED_BIN:-.bin/cloudflared}" ]]; then
    bash tunnel.sh "$PHP" "$PORT" &
    log "HTTPS tunnel for the Telegram Mini App starting…"
fi

# Stop only COHERS's own children — never the whole process group, which other bots
# started by the same start-all.sh share.
children_of() { # children_of PID -> direct child PIDs (works without procps via /proc)
    if command -v pgrep >/dev/null 2>&1; then pgrep -P "$1"; return; fi
    local stat
    for stat in /proc/[0-9]*/stat; do
        read -r c _ _ ppid _ 2>/dev/null < "$stat" && [[ "$ppid" == "$1" ]] && echo "$c"
    done
}
kill_tree() { local c; for c in $(children_of "$1"); do kill_tree "$c"; done; kill -TERM "$1" 2>/dev/null; }
stop_all() {
    log "Stopping…"
    for pid in $(jobs -p); do kill_tree "$pid"; done
    exit 0
}
trap stop_all SIGTERM SIGINT

# --- web server ------------------------------------------------------------------
log "Dashboard listening on 0.0.0.0:$PORT"
export PHP_CLI_SERVER_WORKERS="${WEB_WORKERS:-4}"
"$PHP" artisan serve --host=0.0.0.0 --port="$PORT" --no-reload &
wait $!
