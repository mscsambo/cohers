#!/bin/bash
# =============================================================================
#  Free HTTPS address for the Telegram Mini App (Cloudflare quick tunnel).
#
#  Started by start.sh when cohers/.bin/cloudflared exists:
#     bash tunnel.sh PHP PORT
#  Each time the tunnel (re)starts it gets a new random https://….trycloudflare.com
#  address; this script prints it and points the bot's menu button at it, so the
#  Mini App keeps working after restarts. No Cloudflare account needed.
# =============================================================================
cd "$(dirname "$0")" || exit 1
PHP="$1"
PORT="$2"
CLOUDFLARED="${CLOUDFLARED_BIN:-$(pwd)/.bin/cloudflared}"
LOG=storage/logs/tunnel.log

log() { printf '[cohers %s] %s\n' "$(date -u +%H:%M:%S)" "$*"; }

chmod +x "$CLOUDFLARED" 2>/dev/null
mkdir -p storage/logs storage/app

while true; do
    : > "$LOG"
    # http2 works where UDP (QUIC) is blocked, as in many containers.
    "$CLOUDFLARED" tunnel --url "http://127.0.0.1:$PORT" --no-autoupdate --protocol http2 >> "$LOG" 2>&1 &
    tunnel_pid=$!

    url=""
    for _ in {1..90}; do
        url="$(grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' "$LOG" | head -1)"
        [[ -n "$url" ]] && break
        kill -0 "$tunnel_pid" 2>/dev/null || break
        sleep 1
    done

    if [[ -n "$url" ]]; then
        echo "$url" > storage/app/tunnel-url.txt
        log "Mini App HTTPS address: $url"
        sleep 5   # let the web server finish starting before Telegram is pointed at it
        if "$PHP" artisan cohers:telegram-menu-button "$url" --no-interaction >> "$LOG" 2>&1; then
            log "Bot menu button now opens $url/tg"
        else
            log "Could not set the bot menu button (bot token missing?). See storage/logs/tunnel.log"
        fi
    else
        log "HTTPS tunnel did not start; retrying in 30s (see storage/logs/tunnel.log)"
        kill "$tunnel_pid" 2>/dev/null
    fi

    wait "$tunnel_pid" 2>/dev/null
    rm -f storage/app/tunnel-url.txt
    [[ -n "$url" ]] && log "HTTPS tunnel stopped; starting a new one (new address) in 30s"
    sleep 30
done
