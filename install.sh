#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# OpenRanch Dashboard — installer.
#
# Creates the database and its user, imports schema.sql, writes config.php from
# config.example.php with your answers filled in, sets file permissions, and
# prints an nginx server block for you to install.
#
# It does NOT touch your web server config, obtain a certificate, or restart
# any service. Those are printed as instructions so you can read them first.
#
# Re-running is safe: it refuses to overwrite an existing config.php, and the
# schema import is idempotent (every CREATE is IF NOT EXISTS).
#
# Usage:  sudo ./install.sh
# ---------------------------------------------------------------------------
set -euo pipefail

SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

c_bold=$'\033[1m'; c_red=$'\033[31m'; c_grn=$'\033[32m'; c_yel=$'\033[33m'; c_off=$'\033[0m'
say()  { printf '%s\n' "$*"; }
ok()   { printf '%s✓%s %s\n' "$c_grn" "$c_off" "$*"; }
warn() { printf '%s!%s %s\n' "$c_yel" "$c_off" "$*"; }
die()  { printf '%s✗ %s%s\n' "$c_red" "$*" "$c_off" >&2; exit 1; }
hdr()  { printf '\n%s== %s ==%s\n' "$c_bold" "$*" "$c_off"; }

[ "$(id -u)" -eq 0 ] || die "run this with sudo — it writes config.php and sets ownership"

for bin in mysql php openssl; do
  command -v "$bin" >/dev/null || die "$bin is not installed. See the requirements in README.md"
done
[ -f "$SRC_DIR/config.example.php" ] || die "config.example.php not found — run this from the repo root"
[ -f "$SRC_DIR/schema.sql" ]         || die "schema.sql not found — run this from the repo root"

if [ -f "$SRC_DIR/config.php" ]; then
  die "config.php already exists. Move it aside first if you really want to reinstall."
fi

# ---------------------------------------------------------------------------
# Prompts
# ---------------------------------------------------------------------------
hdr "OpenRanch install"
say "Answer the prompts; press Enter to accept the [default]."
say ""

ask() {  # ask VAR "prompt" "default"
  local __var="$1" __prompt="$2" __default="${3:-}" __reply
  if [ -n "$__default" ]; then
    read -r -p "$__prompt [$__default]: " __reply || true
    __reply="${__reply:-$__default}"
  else
    while [ -z "${__reply:-}" ]; do read -r -p "$__prompt: " __reply || true; done
  fi
  printf -v "$__var" '%s' "$__reply"
}

ask DB_NAME   "Database name"                      "openranch"
ask DB_USER   "Database user to create"            "openranch"
ask DB_PASS_IN "Password for that user (blank = generate)" " "
DB_PASS="${DB_PASS_IN// /}"
if [ -z "$DB_PASS" ]; then
  DB_PASS="$(openssl rand -base64 24 | tr -d '/+=' | head -c 24)"
  ok "generated a database password"
fi

ask SITE_NAME "Site name (shown in titles and emails)" "OpenRanch"
ask BASE_URL  "Public HTTPS URL, no trailing slash"    "https://dashboard.example.com"
ask ALERT_EMAIL "Email address for outage notifications" "admin@example.com"

ADMIN_PIN_DEFAULT="$(php -r 'echo random_int(10000000, 99999999);')"
ask ADMIN_PIN "Admin PIN (guards every write from the browser)" "$ADMIN_PIN_DEFAULT"

PROVISION_KEY_DEFAULT="$(php -r 'echo bin2hex(random_bytes(16));')"
ask PROVISION_KEY "Provisioning key for self-registering boards" "$PROVISION_KEY_DEFAULT"

ask FLOW_TZ   "Timezone for daily chart day boundaries"  "$(cat /etc/timezone 2>/dev/null || echo UTC)"
ask WEB_ROOT  "Install to which directory"               "/var/www/openranch"
ask WEB_USER  "Web server user"                          "www-data"

id -u "$WEB_USER" >/dev/null 2>&1 || die "user '$WEB_USER' does not exist"
php -r 'new DateTimeZone($argv[1]);' "$FLOW_TZ" 2>/dev/null || die "'$FLOW_TZ' is not a valid PHP timezone"
case "$BASE_URL" in https://*) ;; *) warn "BASE_URL is not https — the PWA and Web Push both require HTTPS" ;; esac

# ---------------------------------------------------------------------------
# Database
# ---------------------------------------------------------------------------
hdr "Database"
sql_escape() { printf '%s' "$1" | sed "s/'/''/g"; }
DB_PASS_SQL="$(sql_escape "$DB_PASS")"

mysql <<SQL || die "could not create the database — is MySQL running, and can root connect without a password?"
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS_SQL}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS_SQL}';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
ok "database '${DB_NAME}' and user '${DB_USER}'@'localhost' ready"

mysql "${DB_NAME}" < "$SRC_DIR/schema.sql" || die "schema import failed"
TABLES=$(mysql -N -B "${DB_NAME}" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}'")
ok "schema imported (${TABLES} tables)"

# ---------------------------------------------------------------------------
# Files
# ---------------------------------------------------------------------------
hdr "Files"
if [ "$WEB_ROOT" != "$SRC_DIR" ]; then
  mkdir -p "$WEB_ROOT"
  # -T so a second run updates in place instead of nesting a copy inside.
  cp -aT "$SRC_DIR" "$WEB_ROOT"
  rm -rf "$WEB_ROOT/.git" "$WEB_ROOT/install.sh"
  ok "copied application to $WEB_ROOT"
else
  ok "installing in place ($WEB_ROOT)"
fi

# Substitute with awk rather than sed: these values can contain / & | and other
# characters that would otherwise need escaping in a sed replacement.
CONFIG="$WEB_ROOT/config.php"
php -r '
  $src = file_get_contents($argv[1]);
  $map = [
    "define(\x27DB_NAME\x27, \x27openranch\x27);"        => "define(\x27DB_NAME\x27, " . var_export($argv[2], true) . ");",
    "define(\x27DB_USER\x27, \x27openranch\x27);"        => "define(\x27DB_USER\x27, " . var_export($argv[3], true) . ");",
    "define(\x27DB_PASS\x27, \x27CHANGE_ME_DB_PASSWORD\x27);"  => "define(\x27DB_PASS\x27, " . var_export($argv[4], true) . ");",
    "define(\x27ADMIN_PIN\x27, \x27CHANGE_ME_ADMIN_PIN\x27);"  => "define(\x27ADMIN_PIN\x27, " . var_export($argv[5], true) . ");",
    "define(\x27PROVISION_KEY\x27, \x27CHANGE_ME_PROVISION_KEY\x27);" => "define(\x27PROVISION_KEY\x27, " . var_export($argv[6], true) . ");",
    "define(\x27ALERT_EMAIL\x27, \x27admin@example.com\x27);"  => "define(\x27ALERT_EMAIL\x27, " . var_export($argv[7], true) . ");",
    "define(\x27SITE_NAME\x27, \x27OpenRanch\x27);"      => "define(\x27SITE_NAME\x27, " . var_export($argv[8], true) . ");",
    "define(\x27BASE_URL\x27,  \x27https://dashboard.example.com\x27);" => "define(\x27BASE_URL\x27,  " . var_export($argv[9], true) . ");",
    "define(\x27FLOW_TZ\x27, \x27UTC\x27);"              => "define(\x27FLOW_TZ\x27, " . var_export($argv[10], true) . ");",
  ];
  foreach ($map as $from => $to) {
    if (strpos($src, $from) === false) { fwrite(STDERR, "placeholder not found: $from\n"); exit(1); }
    $src = str_replace($from, $to, $src);
  }
  file_put_contents($argv[11], $src);
' "$SRC_DIR/config.example.php" "$DB_NAME" "$DB_USER" "$DB_PASS" "$ADMIN_PIN" \
  "$PROVISION_KEY" "$ALERT_EMAIL" "$SITE_NAME" "$BASE_URL" "$FLOW_TZ" "$CONFIG" \
  || die "could not write config.php"

php -l "$CONFIG" >/dev/null || die "generated config.php has a syntax error"
ok "wrote $CONFIG"

# ---------------------------------------------------------------------------
# Permissions
# ---------------------------------------------------------------------------
hdr "Permissions"
chown -R root:"$WEB_USER" "$WEB_ROOT"
find "$WEB_ROOT" -type d -exec chmod 755 {} +
find "$WEB_ROOT" -type f -exec chmod 644 {} +
# config.php holds every secret: readable by the web server, nobody else.
chmod 640 "$CONFIG"
chown root:"$WEB_USER" "$CONFIG"
ok "application readable by $WEB_USER; config.php is 640 root:$WEB_USER"

# ---------------------------------------------------------------------------
# Verify
# ---------------------------------------------------------------------------
hdr "Verify"
sudo -u "$WEB_USER" php -r "require '$CONFIG'; db()->query('SELECT 1'); echo 'db connect ok', PHP_EOL;" \
  || die "the web user cannot connect to the database with the generated config"
ok "$WEB_USER can read config.php and reach the database"

# Prefer the versioned socket (php8.3-fpm.sock) over the php-fpm.sock
# alternatives symlink, which does not exist on every distribution.
PHP_FPM_SOCK=$(ls /run/php/php[0-9]*-fpm.sock 2>/dev/null | sort -V | tail -1 || true)
[ -n "$PHP_FPM_SOCK" ] || PHP_FPM_SOCK=$(ls /run/php/php*-fpm.sock 2>/dev/null | head -1 || true)
[ -n "$PHP_FPM_SOCK" ] || { PHP_FPM_SOCK="/run/php/php8.3-fpm.sock"; warn "no php-fpm socket found; assuming $PHP_FPM_SOCK"; }

SERVER_NAME=$(printf '%s' "$BASE_URL" | sed -E 's#^https?://##; s#/.*$##')

# ---------------------------------------------------------------------------
# Next steps
# ---------------------------------------------------------------------------
hdr "Done — three things left to do"

cat <<EOT

1. Add this nginx server block (e.g. /etc/nginx/sites-available/openranch,
   then symlink into sites-enabled), and reload nginx:

--------------------------------------------------------------------------
server {
    listen 80;
    listen [::]:80;
    server_name ${SERVER_NAME};
    # Everything is HTTPS-only: the PWA and Web Push both require it.
    return 301 https://\$host\$request_uri;
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name ${SERVER_NAME};

    root ${WEB_ROOT};
    index index.php;

    # ssl_certificate     /etc/letsencrypt/live/${SERVER_NAME}/fullchain.pem;
    # ssl_certificate_key /etc/letsencrypt/live/${SERVER_NAME}/privkey.pem;
    # Or just run:  certbot --nginx -d ${SERVER_NAME}

    client_max_body_size 2m;

    location / {
        try_files \$uri \$uri/ =404;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_FPM_SOCK};
    }

    # Never serve secrets or schema as plain text. config.php is only ever
    # executed, never read over HTTP; the rest has no business being public.
    location ~ ^/config\.php\$        { deny all; }
    location ~ \.(bak|sql|conf|env)\$ { deny all; }
    location ~ /\.                    { deny all; }

    # The service worker must not be cached, or clients pin an old build.
    location = /sw.js { add_header Cache-Control "no-cache"; }
}
--------------------------------------------------------------------------

2. Get a certificate if you don't have one:

     sudo certbot --nginx -d ${SERVER_NAME}

3. Open ${BASE_URL}/admin.php and enter your admin PIN to add your first
   device. The four example_* templates are there to show the shape — delete
   them when you don't need them:

     DELETE FROM devices WHERE slug LIKE 'example_%';

EOT

hdr "Credentials — write these down now"
cat <<EOT
  Database        ${DB_NAME}
  DB user         ${DB_USER}@localhost
  DB password     ${DB_PASS}
  Admin PIN       ${ADMIN_PIN}
  Provision key   ${PROVISION_KEY}

These are stored in ${CONFIG} (mode 640). They are NOT in git.
Web Push is off until you add a VAPID keypair — see the comments in config.php.
EOT
