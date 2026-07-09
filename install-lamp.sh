#!/usr/bin/env bash
#
# install-lamp.sh — one-shot PMSRAPI v2 deploy on an Ubuntu 24.04 LAMP server.
#
# Installs Apache + MariaDB + PHP 8.3 (+ Redis), downloads the framework,
# creates the database, writes the secret config OUTSIDE the web root, wires up
# an Apache vhost, and leaves a working REST API at  http://<host>/v2/.
#
#   curl -fsSL https://raw.githubusercontent.com/ruvenss/pmsrapi/master/install-lamp.sh | sudo bash
#
# Everything is non-interactive with sane defaults; override via env vars:
#
#   curl -fsSL .../install-lamp.sh | sudo PMS_NAME=weather PMS_PORT=80 bash
#
#   PMS_NAME        service + database name           (default: pmsrapi)
#   PMS_DIR         install dir (web root)            (default: /var/www/$PMS_NAME)
#   PMS_REF         git tag or branch to deploy       (default: master)
#   PMS_PORT        Apache listen port                (default: 80)
#   PMS_ENV         env: dev|test|stage|prod          (default: prod)
#   PMS_DB_HOST     database host                     (default: localhost)
#   PMS_DB_PASS     database password                 (default: generated)
#   PMS_SERVER_TOKEN  bearer token                    (default: generated)
#   PMS_WITH_REDIS  install + use Redis (yes|no)      (default: yes)
#   PMS_WORKER      install webhook worker (yes|no)   (default: yes)
#
# Re-running is safe: an existing secret config is reused (token + DB password
# are preserved), so it won't lock you out on a second run.

set -euo pipefail

# ── configuration (env-overridable) ───────────────────────────────────────
PMS_REPO="${PMS_REPO:-ruvenss/pmsrapi}"
PMS_NAME="${PMS_NAME:-pmsrapi}"
PMS_DIR="${PMS_DIR:-/var/www/${PMS_NAME}}"
PMS_REF="${PMS_REF:-master}"
PMS_PORT="${PMS_PORT:-80}"
PMS_ENV="${PMS_ENV:-prod}"
PMS_SECRET_DIR="${PMS_SECRET_DIR:-/etc/pmsrapi}"
PMS_STATE_DIR="${PMS_STATE_DIR:-/var/lib/${PMS_NAME}}"
PMS_LOG_DIR="${PMS_LOG_DIR:-/var/log/${PMS_NAME}}"
PMS_DB_NAME="${PMS_DB_NAME:-${PMS_NAME}}"
PMS_DB_USER="${PMS_DB_USER:-${PMS_NAME}}"
PMS_DB_HOST="${PMS_DB_HOST:-localhost}"
PMS_DB_PORT="${PMS_DB_PORT:-3306}"
PMS_WITH_REDIS="${PMS_WITH_REDIS:-yes}"
PMS_WORKER="${PMS_WORKER:-yes}"

SECRET_FILE="${PMS_SECRET_DIR}/${PMS_NAME}.json"
LOG_FILE="${PMS_LOG_DIR}/app.log"
WEBHOOKS_FILE="${PMS_STATE_DIR}/webhooks.json"

# ── output helpers ─────────────────────────────────────────────────────────
if [ -t 1 ]; then C='\033[1;36m'; G='\033[1;32m'; R='\033[1;31m'; Y='\033[1;33m'; Z='\033[0m'; else C=''; G=''; R=''; Y=''; Z=''; fi
step() { printf "\n${C}▶ %s${Z}\n" "$*"; }
info() { printf "  %s\n" "$*"; }
ok()   { printf "  ${G}✓${Z} %s\n" "$*"; }
warn() { printf "  ${Y}!${Z} %s\n" "$*"; }
die()  { printf "${R}✗ %s${Z}\n" "$*" >&2; exit 1; }

# ── preflight ──────────────────────────────────────────────────────────────
[ "$(id -u)" -eq 0 ] || die "Please run as root:  curl -fsSL … | sudo bash"
command -v apt-get >/dev/null 2>&1 || die "This installer targets Ubuntu/Debian (apt-get not found)."
case "$PMS_NAME" in [a-z][a-z0-9_]*) : ;; *) die "PMS_NAME must be lowercase alphanumeric/underscore, starting with a letter." ;; esac

export DEBIAN_FRONTEND=noninteractive

printf "${C}"
cat <<'BANNER'
  ┌────────────────────────────────────────────┐
  │   PMSRAPI v2 · Ubuntu 24 LAMP installer      │
  └────────────────────────────────────────────┘
BANNER
printf "${Z}"
info "service   : ${PMS_NAME}"
info "web root  : ${PMS_DIR}   (served at http://<host>:${PMS_PORT}/v2/)"
info "secret    : ${SECRET_FILE}   (outside web root)"

# ── 1. packages ────────────────────────────────────────────────────────────
step "Installing LAMP + PHP 8.3${PMS_WITH_REDIS:+ + Redis}"
PKGS="apache2 mariadb-server unzip curl ca-certificates openssl \
  php libapache2-mod-php php-cli php-mysql php-mbstring php-intl php-xml php-zip php-curl php-gd php-bcmath"
if [ "$PMS_WITH_REDIS" = "yes" ]; then PKGS="$PKGS redis-server php-redis"; fi
apt-get update -qq
# shellcheck disable=SC2086
apt-get install -y -qq $PKGS >/dev/null
php -v | grep -q "PHP 8\.[3-9]" || warn "PHP 8.3+ recommended; found $(php -r 'echo PHP_VERSION;')."
ok "packages installed"

systemctl enable --now apache2 mariadb >/dev/null 2>&1 || true
[ "$PMS_WITH_REDIS" = "yes" ] && { systemctl enable --now redis-server >/dev/null 2>&1 || true; }

# ── 2. fetch the framework ─────────────────────────────────────────────────
step "Deploying framework to ${PMS_DIR}"
SELF="${BASH_SOURCE[0]:-$0}"
SRC_DIR=""
if [ -f "$SELF" ]; then
    d="$(cd "$(dirname "$SELF")" && pwd)"
    [ -f "$d/v2/index.php" ] && SRC_DIR="$d"
fi
mkdir -p "$PMS_DIR"
if [ -n "$SRC_DIR" ]; then
    info "using local checkout: $SRC_DIR"
    cp -a "$SRC_DIR/." "$PMS_DIR/"
    rm -rf "$PMS_DIR/.git"
else
    tmp="$(mktemp -d)"
    case "$PMS_REF" in
        [0-9]* | v[0-9]*) refpath="tags/${PMS_REF}" ;;
        *)                refpath="heads/${PMS_REF}" ;;
    esac
    url="https://github.com/${PMS_REPO}/archive/refs/${refpath}.zip"
    info "downloading ${url}"
    curl -fsSL "$url" -o "$tmp/src.zip" || die "download failed: $url"
    unzip -q "$tmp/src.zip" -d "$tmp"
    ex="$(find "$tmp" -maxdepth 1 -type d -name "$(basename "$PMS_REPO")-*" | head -1)"
    [ -n "$ex" ] || die "could not unpack release archive"
    cp -a "$ex/." "$PMS_DIR/"
    rm -rf "$tmp"
fi
[ -f "$PMS_DIR/v2/index.php" ] || die "v2 core not found under ${PMS_DIR} after deploy"
ok "framework in place"

# ── 3. secret token + db password (reuse if already present) ───────────────
step "Preparing credentials"
mkdir -p "$PMS_SECRET_DIR" "$PMS_STATE_DIR" "$PMS_LOG_DIR"
TOKEN=""; DB_PASS=""
if [ -f "$SECRET_FILE" ]; then
    TOKEN="$(php -r '$c=json_decode((string)file_get_contents($argv[1]),true); echo is_array($c)?($c["ms_server_token"]??""):"";' "$SECRET_FILE" 2>/dev/null || true)"
    DB_PASS="$(php -r '$c=json_decode((string)file_get_contents($argv[1]),true); echo is_array($c)?($c["db"]["password"]??""):"";' "$SECRET_FILE" 2>/dev/null || true)"
    [ -n "$TOKEN" ] && info "reusing existing token + DB password from ${SECRET_FILE}"
fi
TOKEN="${PMS_SERVER_TOKEN:-${TOKEN:-$(openssl rand -hex 32)}}"
DB_PASS="${PMS_DB_PASS:-${DB_PASS:-$(openssl rand -hex 24)}}"
ok "credentials ready"

# ── 4. database ────────────────────────────────────────────────────────────
step "Creating database '${PMS_DB_NAME}' and user '${PMS_DB_USER}'"
mariadb <<SQL
CREATE DATABASE IF NOT EXISTS \`${PMS_DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${PMS_DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${PMS_DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${PMS_DB_NAME}\`.* TO '${PMS_DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
ok "database ready"

# ── 5. secret config (outside the web root) ────────────────────────────────
step "Writing secret config ${SECRET_FILE}"
REDIS_BLOCK=""
if [ "$PMS_WITH_REDIS" = "yes" ]; then
    REDIS_BLOCK='  "redis": { "host": "127.0.0.1", "port": 6379, "db": 0 },
'
fi
cat > "$SECRET_FILE" <<JSON
{
  "env": "${PMS_ENV}",
  "ms_server_token": "${TOKEN}",
  "db": {
    "host": "${PMS_DB_HOST}",
    "port": ${PMS_DB_PORT},
    "name": "${PMS_DB_NAME}",
    "username": "${PMS_DB_USER}",
    "password": "${DB_PASS}"
  },
${REDIS_BLOCK}  "cache": { "ttl": 30 },
  "rate_limit": { "fail_open": true },
  "local_log": { "path": "${LOG_FILE}", "level": "errors" },
  "resources": {},
  "universe": []
}
JSON
php -r 'exit(json_validate((string)file_get_contents($argv[1]))?0:1);' "$SECRET_FILE" || die "generated secret JSON is invalid"
ok "secret config written"

# ── 6. point config.php at this service ────────────────────────────────────
step "Configuring config.php"
CFG="$PMS_DIR/v2/config.php"
sed -i "s|'ms_name' => '[^']*'|'ms_name' => '${PMS_NAME}'|" "$CFG"
sed -i "s|'secrets_path' => .*|'secrets_path' => '${SECRET_FILE}',|" "$CFG"
sed -i "s|'webhooks_path' => .*|'webhooks_path' => '${WEBHOOKS_FILE}',|" "$CFG"
php -l "$CFG" >/dev/null || die "config.php is broken after edit"
ok "config.php points at ${PMS_NAME} + ${SECRET_FILE}"

# ── 7. permissions (read-only code, writable state elsewhere) ──────────────
step "Setting permissions"
# Code owned by root, only readable by the runtime user → a file-write bug can't
# rewrite the app (see .claude/rules/sec-writable-state-outside-code).
chown -R root:www-data "$PMS_DIR"
chmod -R u=rwX,g=rX,o= "$PMS_DIR"
# Secret: readable (not writable) by www-data.
chown root:www-data "$SECRET_FILE"; chmod 640 "$SECRET_FILE"
chown root:www-data "$PMS_SECRET_DIR"; chmod 750 "$PMS_SECRET_DIR"
# Writable state + logs are OUTSIDE the code tree and owned by the runtime user.
chown -R www-data:www-data "$PMS_STATE_DIR" "$PMS_LOG_DIR"
chmod 750 "$PMS_STATE_DIR" "$PMS_LOG_DIR"
ok "permissions set"

# ── 8. apache vhost ────────────────────────────────────────────────────────
step "Configuring Apache vhost on port ${PMS_PORT}"
a2enmod rewrite headers >/dev/null 2>&1 || true
if [ "$PMS_PORT" != "80" ] && ! grep -qE "^\s*Listen\s+${PMS_PORT}\b" /etc/apache2/ports.conf; then
    echo "Listen ${PMS_PORT}" >> /etc/apache2/ports.conf
fi
cat > "/etc/apache2/sites-available/${PMS_NAME}.conf" <<VHOST
<VirtualHost *:${PMS_PORT}>
    ServerName ${PMS_NAME}
    DocumentRoot ${PMS_DIR}

    # Expose ONLY the v2 application; hide framework sources (README, install
    # scripts, v1 tree, manifest, git metadata) even though they live in the root.
    <Directory ${PMS_DIR}>
        Require all denied
    </Directory>
    <Directory ${PMS_DIR}/v2>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    RedirectMatch 301 "^/\$" "/v2/"

    ErrorLog \${APACHE_LOG_DIR}/${PMS_NAME}-error.log
    CustomLog \${APACHE_LOG_DIR}/${PMS_NAME}-access.log combined
</VirtualHost>
VHOST
a2ensite "${PMS_NAME}.conf" >/dev/null 2>&1 || true
a2dissite 000-default.conf >/dev/null 2>&1 || true
apache2ctl configtest >/dev/null 2>&1 || die "Apache config test failed — check /etc/apache2/sites-available/${PMS_NAME}.conf"
systemctl reload apache2 || systemctl restart apache2
ok "Apache serving ${PMS_DIR}/v2 at port ${PMS_PORT}"

# ── 9. webhook worker (optional; needs Redis) ──────────────────────────────
if [ "$PMS_WORKER" = "yes" ] && [ "$PMS_WITH_REDIS" = "yes" ]; then
    step "Installing webhook worker service"
    cat > "/etc/systemd/system/${PMS_NAME}-worker.service" <<UNIT
[Unit]
Description=PMSRAPI v2 webhook worker (${PMS_NAME})
After=network.target redis-server.service mariadb.service

[Service]
User=www-data
Group=www-data
ExecStart=/usr/bin/php ${PMS_DIR}/v2/worker.php
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
UNIT
    systemctl daemon-reload
    systemctl enable --now "${PMS_NAME}-worker.service" >/dev/null 2>&1 || warn "worker not started (Redis required); start later with: systemctl start ${PMS_NAME}-worker"
    ok "webhook worker installed"
fi

# ── done ───────────────────────────────────────────────────────────────────
IP="$(hostname -I 2>/dev/null | awk '{print $1}')"; IP="${IP:-localhost}"
BASE="http://${IP}$([ "$PMS_PORT" = "80" ] && echo "" || echo ":${PMS_PORT}")/v2"
printf "\n${G}✅ PMSRAPI v2 is live${Z}\n"
printf "%s\n" "──────────────────────────────────────────────"
info "Base URL : ${BASE}"
info "Token    : ${TOKEN}"
info "Secret   : ${SECRET_FILE}"
printf "\n${C}Try it:${Z}\n"
printf "  curl -s %s/health\n" "$BASE"
printf "  curl -s -H 'Authorization: Bearer %s' %s/example/hello/World\n" "$TOKEN" "$BASE"
printf "  curl -s -H 'Authorization: Bearer %s' %s/info\n" "$TOKEN" "$BASE"
printf "\n${C}Next:${Z}\n"
info "• Expose a table over REST: add it to \"resources\" in ${SECRET_FILE}"
info "• Build a feature as a plugin:  cd ${PMS_DIR} && bash v2/plugin.sh"
info "• Full guide: ${PMS_DIR}/v2/docs/index.html"
