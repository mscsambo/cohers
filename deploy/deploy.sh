#!/usr/bin/env bash
# =============================================================================
#  COHERS installer / updater for Ubuntu 22.04 or 24.04
#
#  First install:   sudo bash deploy.sh /root/cohers.tar.gz
#  Update later:    sudo bash deploy.sh /root/cohers.tar.gz     (same command)
#
#  What it does (and does NOT touch):
#    + installs PHP 8.2 (+FPM), Composer, MySQL, Supervisor, Certbot if missing
#    + unpacks the app to /var/www/cohers, keeps .env and uploaded photos on update
#    + creates its own MySQL database/user, its own nginx site, its own
#      supervisor worker and cron entry
#    - never edits or removes other nginx sites, databases or PHP versions
#
#  Optional non-interactive use: export DOMAIN, LE_EMAIL, ADMIN_EMAIL,
#  SEED_DEMO=y|n, TELEGRAM_BOT_TOKEN, TELEGRAM_BOT_USERNAME, GEMINI_API_KEY,
#  MYSQL_ROOT_PASSWORD (only if root needs a password) before running.
# =============================================================================
set -Eeuo pipefail

PKG="${1:-}"
APP_DIR="${APP_DIR:-/var/www/cohers}"
PHP_V="8.2"
DB_NAME="cohers"
DB_USER="cohers"
LOG="/var/log/cohers-deploy.log"

c_info()  { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
c_ok()    { printf '\033[1;32m  ✔ %s\033[0m\n' "$*"; }
c_warn()  { printf '\033[1;33m  ! %s\033[0m\n' "$*"; }
c_fail()  { printf '\033[1;31m  ✖ %s\033[0m\n' "$*"; exit 1; }
trap 'c_fail "Failed at line $LINENO. Details: $LOG"' ERR
exec > >(tee -a "$LOG") 2>&1

ask() { # ask VAR "Question" "default"
    local var="$1" q="$2" def="${3:-}" ans
    if [[ -n "${!var:-}" ]]; then return; fi
    if [[ -t 0 ]]; then read -rp "  $q${def:+ [$def]}: " ans; else ans=""; fi
    printf -v "$var" '%s' "${ans:-$def}"
}

set_env() { # set_env KEY VALUE  (replaces "KEY=" or "# KEY=" or appends)
    local key="$1" val="$2" file="$APP_DIR/.env" esc
    esc=$(printf '%s' "$val" | sed -e 's/[\/&|]/\\&/g')
    if grep -qE "^#?\s*${key}=" "$file"; then
        sed -i -E "s|^#?\s*${key}=.*|${key}=${esc}|" "$file"
    else
        printf '%s=%s\n' "$key" "$val" >> "$file"
    fi
}

PHP_BIN="/usr/bin/php${PHP_V}"   # always call PHP 8.2 explicitly; the server's default php is left alone
artisan() { sudo -u www-data "$PHP_BIN" "$APP_DIR/artisan" "$@"; }

# ----------------------------------------------------------------------------
c_info "Pre-flight checks"
[[ $EUID -eq 0 ]] || c_fail "Run as root (sudo bash deploy.sh ...)."
[[ -n "$PKG" && -f "$PKG" ]] || c_fail "Package not found. Usage: sudo bash deploy.sh /root/cohers.tar.gz"
. /etc/os-release
[[ "$ID" == "ubuntu" ]] || c_warn "Tested on Ubuntu; detected $PRETTY_NAME."
c_ok "$PRETTY_NAME, package $(du -h "$PKG" | cut -f1)"

FIRST_INSTALL=1
[[ -f "$APP_DIR/.env" ]] && FIRST_INSTALL=0

if [[ $FIRST_INSTALL -eq 1 ]]; then
    c_info "A few questions (press Enter for the default)"
    ask DOMAIN "Domain for COHERS" "cohers.anajak.cloud"
    ask LE_EMAIL "Email for the Let's Encrypt certificate" ""
    ask ADMIN_EMAIL "Your administrator login email (empty = skip)" ""
    ask SEED_DEMO "Load demo data (150 sample reports)? y/n" "y"
    ask TELEGRAM_BOT_TOKEN "Telegram bot token (empty = set later in Admin > Configuration)" ""
    ask TELEGRAM_BOT_USERNAME "Telegram bot username" "cohers_bot"
    ask GEMINI_API_KEY "Gemini API key (empty = offline keyword triage)" ""
else
    DOMAIN=$(grep -E '^APP_URL=' "$APP_DIR/.env" | sed -E 's|APP_URL=https?://||; s|/.*||')
    c_ok "Existing install found for $DOMAIN — running an update (data and .env are kept)"
fi

# ----------------------------------------------------------------------------
c_info "Installing system packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq software-properties-common ca-certificates curl unzip git openssl cron >/dev/null
if ! apt-cache policy "php${PHP_V}-fpm" | grep -q 'Candidate: [0-9]'; then
    add-apt-repository -y ppa:ondrej/php >/dev/null
    apt-get update -qq
fi
apt-get install -y -qq "php${PHP_V}-cli" "php${PHP_V}-fpm" "php${PHP_V}-mysql" "php${PHP_V}-sqlite3" \
    "php${PHP_V}-mbstring" "php${PHP_V}-xml" "php${PHP_V}-curl" "php${PHP_V}-zip" "php${PHP_V}-bcmath" \
    "php${PHP_V}-intl" "php${PHP_V}-gd" nginx supervisor certbot python3-certbot-nginx >/dev/null
if ! command -v mysql >/dev/null; then
    apt-get install -y -qq mysql-server >/dev/null
fi
systemctl enable --now "php${PHP_V}-fpm" mysql supervisor cron >/dev/null 2>&1 || systemctl enable --now "php${PHP_V}-fpm" mariadb supervisor cron >/dev/null 2>&1 || true
c_ok "PHP $($PHP_BIN -r 'echo PHP_VERSION;'), nginx $(nginx -v 2>&1 | sed 's|.*/||'), $(mysql --version | awk '{print $1,$2,$3}')"

if ! command -v composer >/dev/null; then
    c_info "Installing Composer"
    EXPECTED=$(curl -fsSL https://composer.github.io/installer.sig)
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    ACTUAL=$(sha384sum /tmp/composer-setup.php | awk '{print $1}')
    [[ "$EXPECTED" == "$ACTUAL" ]] || c_fail "Composer installer checksum mismatch"
    $PHP_BIN /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
fi
COMPOSER_BIN="$(command -v composer)"
c_ok "$($PHP_BIN "$COMPOSER_BIN" --version 2>/dev/null | head -1)"

# ----------------------------------------------------------------------------
if [[ $FIRST_INSTALL -eq 1 ]]; then
    c_info "Creating MySQL database '$DB_NAME'"
    DB_PASS=$(openssl rand -hex 20)
    MYSQL=(mysql -uroot)
    [[ -n "${MYSQL_ROOT_PASSWORD:-}" ]] && MYSQL+=(-p"$MYSQL_ROOT_PASSWORD")
    "${MYSQL[@]}" -e "SELECT 1" >/dev/null 2>&1 || c_fail "Cannot log in to MySQL as root. Re-run with: MYSQL_ROOT_PASSWORD='...' sudo -E bash deploy.sh $PKG"
    "${MYSQL[@]}" <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
    c_ok "Database and user ready (password stored only in $APP_DIR/.env)"
fi

# ----------------------------------------------------------------------------
c_info "Unpacking application to $APP_DIR"
mkdir -p "$APP_DIR"
tar -xzf "$PKG" -C "$APP_DIR" --no-same-owner
mkdir -p "$APP_DIR"/storage/{app/private,framework/{cache/data,sessions,views},logs} "$APP_DIR/bootstrap/cache"
c_ok "Code unpacked"

if [[ $FIRST_INSTALL -eq 1 ]]; then
    c_info "Writing production .env"
    cp "$APP_DIR/.env.example" "$APP_DIR/.env"
    set_env APP_NAME COHERS
    set_env APP_ENV production
    set_env APP_DEBUG false
    set_env APP_URL "https://$DOMAIN"
    set_env APP_TIMEZONE UTC
    set_env LOG_LEVEL warning
    set_env DB_CONNECTION mysql
    set_env DB_HOST 127.0.0.1
    set_env DB_PORT 3306
    set_env DB_DATABASE "$DB_NAME"
    set_env DB_USERNAME "$DB_USER"
    set_env DB_PASSWORD "$DB_PASS"
    set_env SESSION_DRIVER database
    set_env SESSION_SECURE_COOKIE true
    set_env QUEUE_CONNECTION database
    set_env CACHE_STORE database
    set_env COHERS_REQUIRE_MFA true
    set_env TELEGRAM_BOT_TOKEN "$TELEGRAM_BOT_TOKEN"
    set_env TELEGRAM_BOT_USERNAME "${TELEGRAM_BOT_USERNAME#@}"
    set_env TELEGRAM_WEBHOOK_SECRET "$(openssl rand -hex 24)"
    set_env GEMINI_API_KEY "$GEMINI_API_KEY"
    if [[ -n "$GEMINI_API_KEY" ]]; then set_env COHERS_AI_DRIVER gemini; else set_env COHERS_AI_DRIVER keyword; fi
    chmod 640 "$APP_DIR/.env"
    c_ok ".env created"
fi

chown -R www-data:www-data "$APP_DIR"
chmod -R ug+rwX "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"

c_info "Installing PHP dependencies (composer, no dev packages)"
sudo -u www-data COMPOSER_HOME=/tmp/composer-cohers "$PHP_BIN" "$COMPOSER_BIN" install -d "$APP_DIR" \
    --no-dev --optimize-autoloader --no-interaction --no-progress --quiet
c_ok "Dependencies installed"

grep -qE '^APP_KEY=base64:' "$APP_DIR/.env" || artisan key:generate --force --no-interaction

# ----------------------------------------------------------------------------
c_info "Database migrations"
artisan down --retry=30 >/dev/null 2>&1 || true
artisan migrate --force --no-interaction
if [[ $FIRST_INSTALL -eq 1 ]]; then
    artisan db:seed --class=Database\\Seeders\\ReferenceDataSeeder --force --no-interaction
    c_ok "Roles, Prey Veng geography, categories and safety rules loaded"
    if [[ "${SEED_DEMO,,}" == y* ]]; then
        artisan db:seed --class=Database\\Seeders\\DemoSeeder --force --no-interaction
        c_info "Demo accounts (random passwords — SAVE THEM)"
        artisan cohers:secure-demo
    fi
    if [[ -n "${ADMIN_EMAIL:-}" ]]; then
        c_info "Your administrator account (SAVE THIS PASSWORD)"
        artisan cohers:create-admin "$ADMIN_EMAIL" --name="Administrator"
    fi
fi
artisan optimize:clear >/dev/null
artisan optimize >/dev/null
artisan up >/dev/null
c_ok "Database ready, caches built"

# ----------------------------------------------------------------------------
c_info "nginx site for $DOMAIN"
if nginx -T 2>/dev/null | grep -q 'sites-enabled'; then
    SITE="/etc/nginx/sites-available/cohers.conf"; LINK="/etc/nginx/sites-enabled/cohers.conf"
else
    SITE="/etc/nginx/conf.d/cohers.conf"; LINK=""
fi
if [[ ! -f "$SITE" ]]; then
    cat > "$SITE" <<NGINX
# Managed by COHERS deploy.sh
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;
    root $APP_DIR/public;
    index index.php;
    charset utf-8;
    client_max_body_size 20m;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }
    location ~ \.php\$ {
        fastcgi_pass unix:/run/php/php${PHP_V}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 60;
    }
    location ~ /\.(?!well-known).* { deny all; }
}
NGINX
    [[ -n "$LINK" ]] && ln -sf "$SITE" "$LINK"
    c_ok "Created $SITE"
else
    c_ok "Keeping existing $SITE"
fi
nginx -t >/dev/null 2>&1 || { nginx -t; c_fail "nginx config test failed — nothing reloaded"; }
systemctl reload nginx
if command -v ufw >/dev/null && ufw status | grep -q 'Status: active'; then
    ufw allow 'Nginx Full' >/dev/null && c_ok "ufw: allowed HTTP/HTTPS"
fi

# ----------------------------------------------------------------------------
c_info "HTTPS certificate"
HTTPS_OK=0
if [[ -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ]]; then
    HTTPS_OK=1; c_ok "Certificate already present"
else
    PROBE="cohers-probe-$(openssl rand -hex 6)"
    mkdir -p "$APP_DIR/public/.well-known"; echo "$PROBE" > "$APP_DIR/public/.well-known/$PROBE"
    if curl -fs -m 10 "http://$DOMAIN/.well-known/$PROBE" | grep -q "$PROBE"; then
        c_ok "http://$DOMAIN reaches this server"
    else
        c_warn "http://$DOMAIN did not answer from this server (DNS not ready, NAT, or firewall). Trying certbot anyway."
    fi
    rm -f "$APP_DIR/public/.well-known/$PROBE"
    if [[ -n "${LE_EMAIL:-}" ]]; then CB_MAIL=(-m "$LE_EMAIL"); else CB_MAIL=(--register-unsafely-without-email); fi
    if certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos "${CB_MAIL[@]}" --redirect; then
        HTTPS_OK=1; c_ok "HTTPS enabled with automatic renewal"
    else
        c_warn "Certificate not issued. Fix DNS (A record $DOMAIN -> this server, DNS only) then run:"
        c_warn "  certbot --nginx -d $DOMAIN --redirect"
    fi
fi
if [[ $HTTPS_OK -eq 1 ]]; then
    set_env APP_URL "https://$DOMAIN"; set_env SESSION_SECURE_COOKIE true
else
    # Secure-only cookies would make sign-in impossible over plain HTTP.
    set_env APP_URL "http://$DOMAIN"; set_env SESSION_SECURE_COOKIE false
    c_warn "Running on plain HTTP for now. After HTTPS works, re-run this script to switch back."
fi
artisan optimize >/dev/null

# ----------------------------------------------------------------------------
c_info "Background services (queue worker + scheduler)"
cat > /etc/supervisor/conf.d/cohers-worker.conf <<SUP
[program:cohers-worker]
command=$PHP_BIN $APP_DIR/artisan queue:work --sleep=3 --tries=5 --backoff=10 --max-time=3600
directory=$APP_DIR
user=www-data
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=60
redirect_stderr=true
stdout_logfile=$APP_DIR/storage/logs/worker.log
stdout_logfile_maxbytes=10MB
SUP
supervisorctl reread >/dev/null && supervisorctl update >/dev/null
artisan queue:restart >/dev/null
supervisorctl restart cohers-worker >/dev/null 2>&1 || supervisorctl start cohers-worker >/dev/null
echo "* * * * * www-data cd $APP_DIR && $PHP_BIN artisan schedule:run >> /dev/null 2>&1" > /etc/cron.d/cohers
chmod 644 /etc/cron.d/cohers
c_ok "Worker: $(supervisorctl status cohers-worker | awk '{print $2}') · scheduler: every minute via /etc/cron.d/cohers"

# ----------------------------------------------------------------------------
if [[ $HTTPS_OK -eq 1 ]] && grep -qE '^TELEGRAM_BOT_TOKEN=.+' "$APP_DIR/.env"; then
    c_info "Telegram webhook"
    if artisan cohers:telegram-webhook "https://$DOMAIN"; then
        c_ok "Bot now delivers updates to https://$DOMAIN/api/telegram/webhook"
    else
        c_warn "Webhook not set — check the token in Admin > Configuration, then use 'Set webhook' there."
    fi
fi

# ----------------------------------------------------------------------------
SCHEME=$([[ $HTTPS_OK -eq 1 ]] && echo https || echo http)
c_info "Done"
cat <<DONE

  Dashboard:  $SCHEME://$DOMAIN
  App folder: $APP_DIR      Log of this run: $LOG
  Worker log: $APP_DIR/storage/logs/worker.log
  App log:    $APP_DIR/storage/logs/laravel.log

  Useful commands:
    supervisorctl status cohers-worker          # queue worker running?
    sudo -u www-data php $APP_DIR/artisan cohers:create-admin you@example.org
    sudo bash deploy.sh /root/cohers.tar.gz     # update after uploading a new package

DONE
