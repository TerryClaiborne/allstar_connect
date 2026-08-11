#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="/var/www/html/allstar_connect"
WEB_USER="www-data"
WEB_GROUP="www-data"
CONFIG_FILE="$APP_DIR/config.ini"
CONFIG_EXAMPLE="$APP_DIR/config.ini.example"
FAVORITES_FILE="$APP_DIR/data/favorites.txt"
DTMF_FAVORITES_FILE="$APP_DIR/data/dtmf_favorites.txt"
DTMF_FAVORITES_EXAMPLE="$APP_DIR/data/dtmf_favorites.example.txt"
HELPER="$APP_DIR/bin/allstar-connect-read.sh"
CONTROL_HELPER="$APP_DIR/bin/allstar-connect-control.sh"
SUDOERS_FILE="/etc/sudoers.d/allstar-connect-read"
APACHE_CONF_NAME="allstar-connect"
APACHE_CONF="/etc/apache2/conf-available/${APACHE_CONF_NAME}.conf"
LOG_DIR="/var/log/allstar-connect"
ACTIVITY_LOG="$LOG_DIR/activity.log"
LOGROTATE_FILE="/etc/logrotate.d/allstar-connect"
ACTION="install"

case "${1:-}" in
  "") ;;
  --set-admin-password|--auth) ACTION="set-password" ;;
  --enable-auth) ACTION="enable-auth" ;;
  --disable-auth) ACTION="disable-auth" ;;
  --help|-h)
    cat <<USAGE
Usage:
  sudo $APP_DIR/setup_allstar_connect.sh
  sudo $APP_DIR/setup_allstar_connect.sh --set-admin-password
  sudo $APP_DIR/setup_allstar_connect.sh --enable-auth
  sudo $APP_DIR/setup_allstar_connect.sh --disable-auth

Normal setup installs or updates permissions, helpers, Apache protection,
access-log filtering, and log rotation. Existing config.ini, favorites.txt, and dtmf_favorites.txt contents are preserved.
USAGE
    exit 0
    ;;
  *) echo "Unknown option: ${1:-}" >&2; exit 2 ;;
esac

fail() {
  echo "[ERROR] $*" >&2
  exit 1
}

[[ ${EUID:-$(id -u)} -eq 0 ]] || fail "Run this installer with sudo or as root."
[[ -d "$APP_DIR" ]] || fail "Missing application directory: $APP_DIR"
[[ -f "$CONFIG_EXAMPLE" ]] || fail "Missing $CONFIG_EXAMPLE"
[[ -f "$DTMF_FAVORITES_EXAMPLE" ]] || fail "Missing $DTMF_FAVORITES_EXAMPLE"

get_key() {
  local key="$1" file="$2"
  awk -F= -v k="$key" '$1 ~ "^[[:space:]]*" k "[[:space:]]*$" {
      v=$2
      sub(/^[[:space:]]+/, "", v)
      sub(/[[:space:]]+$/, "", v)
      gsub(/^"|"$/, "", v)
      print v
      exit
  }' "$file" 2>/dev/null || true
}

set_key() {
  local key="$1" value="$2" file="$3" tmp
  tmp="$(mktemp)"
  awk -v k="$key" -v v="$value" '
    BEGIN { done=0 }
    $0 ~ "^[[:space:]]*" k "[[:space:]]*=" {
      if (!done) { print k "=" v; done=1 }
      next
    }
    { print }
    END { if (!done) print k "=" v }
  ' "$file" > "$tmp"
  install -o root -g "$WEB_GROUP" -m 0640 "$tmp" "$file"
  rm -f "$tmp"
}

show_auth_status() {
  local enabled user saved_hash
  enabled="$(get_key ALLSTAR_CONNECT_AUTH_ENABLED "$CONFIG_FILE")"
  user="$(get_key ALLSTAR_CONNECT_ADMIN_USER "$CONFIG_FILE")"
  saved_hash="$(get_key ALLSTAR_CONNECT_ADMIN_PASSWORD_HASH "$CONFIG_FILE")"
  [[ -n "$user" ]] || user="admin"

  echo
  if [[ "$enabled" == "1" && -n "$saved_hash" ]]; then
    echo "Web login: ENABLED (user: $user)"
    echo "Change the administrator password:"
    echo "sudo $APP_DIR/setup_allstar_connect.sh --set-admin-password"
    echo "Disable login while preserving the saved password hash:"
    echo "sudo $APP_DIR/setup_allstar_connect.sh --disable-auth"
  elif [[ "$enabled" == "1" ]]; then
    echo "[WARN] Web login is enabled, but no administrator password hash is saved."
    echo "Set the administrator password now:"
    echo "sudo $APP_DIR/setup_allstar_connect.sh --set-admin-password"
  else
    echo "[WARN] Web login is DISABLED."
    echo "Anyone who can reach this dashboard can use its control functions."
    echo "Set an administrator password and enable login:"
    echo "sudo $APP_DIR/setup_allstar_connect.sh --set-admin-password"
    if [[ -n "$saved_hash" ]]; then
      echo "Re-enable login using the saved password hash:"
      echo "sudo $APP_DIR/setup_allstar_connect.sh --enable-auth"
    fi
  fi
}

find_rpt_files() {
  local root="/etc/asterisk/rpt.conf"
  [[ -r "$root" ]] || return 0
  printf '%s\n' "$root"
  awk '
    /^[[:space:]]*#include[[:space:]]+/ {
      p=$2
      gsub(/["<>]/, "", p)
      if (p ~ /^\//) print p; else print "/etc/asterisk/" p
    }
  ' "$root" 2>/dev/null
}

detect_node() {
  local candidates="" file section

  if [[ -r /etc/asterisk/savenode.conf ]]; then
    candidates="$(
      sed -nE \
        's/^[[:space:]]*NODE[[:space:]]*=[[:space:]]*"?([0-9]{4,7})"?[[:space:]]*([#;].*)?$/\1/p' \
        /etc/asterisk/savenode.conf |
      sort -u
    )"

    if [[ $(printf '%s\n' "$candidates" | sed '/^$/d' | wc -l) -eq 1 ]]; then
      printf '%s' "$candidates"
      return 0
    fi
  fi

  candidates=""
  while IFS= read -r file; do
    [[ -r "$file" ]] || continue
    while IFS= read -r section; do
      candidates+="$section"$'\n'
    done < <(
      sed -nE \
        's/^[[:space:]]*\[([0-9]{4,7})\][[:space:]]*(\([^)]*\))?[[:space:]]*(;.*)?$/\1/p' \
        "$file"
    )
  done < <(find_rpt_files)

  candidates="$(printf '%s' "$candidates" | sed '/^$/d' | sort -u)"
  if [[ $(printf '%s\n' "$candidates" | sed '/^$/d' | wc -l) -eq 1 ]]; then
    printf '%s' "$candidates"
  fi
}

required_files=(
  VERSION README.md config.ini.example setup_allstar_connect.sh
  public/index.php public/favorites.php public/login.php public/logout.php
  public/assets/app-shell.css public/assets/header.js public/assets/allstar-connect.css
  public/assets/allstar-connect.js public/assets/audio-alerts.js public/assets/favorites.js
  api/local.php api/downstream.php api/echolink.php api/identity.php
  api/link.php api/control.php api/favorites.php
  src/CacheMaintenance.php src/Downstream.php src/EchoLink.php src/Monitor.php src/NodeIdentity.php
  app/Support/Config.php app/Support/AppSession.php app/Support/AppAuth.php
  app/Support/AppCsrf.php app/Support/ApiAuthGuard.php
  bin/allstar-connect-read.sh bin/allstar-connect-control.sh
)
for relative in "${required_files[@]}"; do
  [[ -f "$APP_DIR/$relative" ]] || fail "Missing required file: $APP_DIR/$relative"
done

if [[ "$ACTION" != "install" ]]; then
  command -v php >/dev/null 2>&1 || fail "php is not installed or not in PATH."
  [[ -f "$CONFIG_FILE" ]] || fail "Missing config.ini. Run normal setup first."
fi

if [[ "$ACTION" == "set-password" ]]; then
  [[ -r /dev/tty && -w /dev/tty ]] || fail "An interactive terminal is required."
  p1=""; p2=""; hash=""
  IFS= read -r -s -p "New AllStar Connect admin password: " p1 < /dev/tty || fail "Unable to read the password."
  printf '\n' > /dev/tty
  [[ -n "$p1" ]] || fail "Password cannot be empty."
  IFS= read -r -s -p "Confirm password: " p2 < /dev/tty || { printf '\n' > /dev/tty; fail "Unable to read the confirmation."; }
  printf '\n' > /dev/tty
  [[ "$p1" == "$p2" ]] || { unset p1 p2; fail "Passwords did not match."; }
  hash="$(printf '%s' "$p1" | php -r '$p=stream_get_contents(STDIN); $h=password_hash($p, PASSWORD_DEFAULT); if(!is_string($h)) exit(1); echo $h;')" || fail "Password hashing failed."
  unset p1 p2
  [[ -n "$hash" ]] || fail "Password hashing failed."
  set_key ALLSTAR_CONNECT_ADMIN_PASSWORD_HASH "\"$hash\"" "$CONFIG_FILE"
  set_key ALLSTAR_CONNECT_AUTH_ENABLED "1" "$CONFIG_FILE"
  unset hash
  echo "[OK] AllStar Connect login is enabled."
  exit 0
fi

if [[ "$ACTION" == "enable-auth" ]]; then
  saved_hash="$(get_key ALLSTAR_CONNECT_ADMIN_PASSWORD_HASH "$CONFIG_FILE")"
  [[ -n "$saved_hash" ]] || fail "No saved password hash exists. Run --set-admin-password first."
  unset saved_hash
  set_key ALLSTAR_CONNECT_AUTH_ENABLED "1" "$CONFIG_FILE"
  echo "[OK] AllStar Connect login is enabled using the saved password hash."
  exit 0
fi

if [[ "$ACTION" == "disable-auth" ]]; then
  set_key ALLSTAR_CONNECT_AUTH_ENABLED "0" "$CONFIG_FILE"
  echo "[OK] AllStar Connect login is disabled; the saved password hash was preserved."
  exit 0
fi

for cmd in php sudo visudo apache2ctl a2enconf a2disconf systemctl tar logrotate; do
  command -v "$cmd" >/dev/null 2>&1 || fail "Required command is missing: $cmd"
done
[[ -x /usr/sbin/asterisk ]] || fail "Asterisk was not found at /usr/sbin/asterisk."
id "$WEB_USER" >/dev/null 2>&1 || fail "Web user does not exist: $WEB_USER"

# AllStar callsign search uses ASL3's own astdb.txt updater.
# Keep this best-effort so a temporary package/network/systemd problem
# never prevents the rest of AllStar Connect from installing.
if ! command -v asl3-update-astdb >/dev/null 2>&1; then
  if command -v apt-get >/dev/null 2>&1; then
    if ! DEBIAN_FRONTEND=noninteractive apt-get install -y asl3-update-nodelist >/dev/null 2>&1; then
      if DEBIAN_FRONTEND=noninteractive apt-get update >/dev/null 2>&1; then
        DEBIAN_FRONTEND=noninteractive apt-get install -y asl3-update-nodelist >/dev/null 2>&1 \
          || echo "[WARN] Unable to install asl3-update-nodelist; AllStar callsign search may be unavailable." >&2
      else
        echo "[WARN] Unable to refresh package metadata; AllStar callsign search may be unavailable." >&2
      fi
    fi
  else
    echo "[WARN] asl3-update-astdb is missing and apt-get is unavailable; AllStar callsign search may be unavailable." >&2
  fi
fi

if command -v asl3-update-astdb >/dev/null 2>&1 \
    && systemctl cat asl3-update-astdb.service >/dev/null 2>&1 \
    && systemctl cat asl3-update-astdb.timer >/dev/null 2>&1; then

  if ! systemctl is-enabled --quiet asl3-update-astdb.service; then
    systemctl enable asl3-update-astdb.service >/dev/null 2>&1 \
      || echo "[WARN] Unable to enable asl3-update-astdb.service." >&2
  fi

  if ! systemctl is-enabled --quiet asl3-update-astdb.timer; then
    systemctl enable asl3-update-astdb.timer >/dev/null 2>&1 \
      || echo "[WARN] Unable to enable asl3-update-astdb.timer." >&2
  fi

  if ! systemctl is-active --quiet asl3-update-astdb.timer; then
    systemctl start asl3-update-astdb.timer >/dev/null 2>&1 \
      || echo "[WARN] Unable to start asl3-update-astdb.timer." >&2
  fi

  if [[ ! -s /var/lib/asterisk/astdb.txt ]]; then
    systemctl start asl3-update-astdb.service >/dev/null 2>&1 \
      || echo "[WARN] Unable to create astdb.txt; AllStar callsign search may be unavailable." >&2
  fi
fi

if [[ ! -s /var/lib/asterisk/astdb.txt ]]; then
  echo "[WARN] /var/lib/asterisk/astdb.txt is missing or empty; AllStar callsign search will remain unavailable until ASL3 creates it." >&2
fi

while IFS= read -r php_file; do
  php -l "$php_file" >/dev/null || fail "PHP syntax failed: $php_file"
done < <(find "$APP_DIR/app" "$APP_DIR/api" "$APP_DIR/public" "$APP_DIR/src" -type f -name '*.php' -print | sort)
bash -n "$APP_DIR/setup_allstar_connect.sh"
bash -n "$HELPER"
bash -n "$CONTROL_HELPER"
if command -v node >/dev/null 2>&1; then
  while IFS= read -r js_file; do
    node --check "$js_file" >/dev/null || fail "JavaScript syntax failed: $js_file"
  done < <(find "$APP_DIR/public/assets" -type f -name '*.js' -print | sort)
fi

preserve_dir="$(mktemp -d)"
had_config=0
had_favorites=0
had_dtmf_favorites=0
apache_sites_backup=""
apache_conf_backup=""
if [[ -f "$CONFIG_FILE" ]]; then
  cp -a "$CONFIG_FILE" "$preserve_dir/config.ini"
  had_config=1
fi
if [[ -f "$FAVORITES_FILE" ]]; then
  cp -a "$FAVORITES_FILE" "$preserve_dir/favorites.txt"
  had_favorites=1
fi
if [[ -f "$DTMF_FAVORITES_FILE" ]]; then
  cp -a "$DTMF_FAVORITES_FILE" "$preserve_dir/dtmf_favorites.txt"
  had_dtmf_favorites=1
fi

restore_preserved_local_files() {
  if [[ "$had_config" == "1" ]]; then
    if [[ ! -f "$CONFIG_FILE" ]] || ! cmp -s "$preserve_dir/config.ini" "$CONFIG_FILE"; then
      install -o root -g "$WEB_GROUP" -m 0640 "$preserve_dir/config.ini" "$CONFIG_FILE"
    fi
  else
    rm -f "$CONFIG_FILE"
  fi

  if [[ "$had_favorites" == "1" ]]; then
    if [[ ! -f "$FAVORITES_FILE" ]] || ! cmp -s "$preserve_dir/favorites.txt" "$FAVORITES_FILE"; then
      install -d -o www-data -g www-data -m 0750 "$(dirname "$FAVORITES_FILE")"
      install -o www-data -g www-data -m 0640 "$preserve_dir/favorites.txt" "$FAVORITES_FILE"
    fi
  else
    rm -f "$FAVORITES_FILE"
  fi

  if [[ "$had_dtmf_favorites" == "1" ]]; then
    if [[ ! -f "$DTMF_FAVORITES_FILE" ]] || ! cmp -s "$preserve_dir/dtmf_favorites.txt" "$DTMF_FAVORITES_FILE"; then
      install -d -o www-data -g www-data -m 0750 "$(dirname "$DTMF_FAVORITES_FILE")"
      install -o www-data -g www-data -m 0640 "$preserve_dir/dtmf_favorites.txt" "$DTMF_FAVORITES_FILE"
    fi
  else
    rm -f "$DTMF_FAVORITES_FILE"
  fi
}

installer_cleanup() {
  local status=$?
  trap - EXIT
  if [[ "$status" -ne 0 ]]; then
    restore_preserved_local_files || true
  fi
  [[ -n "$apache_sites_backup" ]] && rm -f "$apache_sites_backup"
  [[ -n "$apache_conf_backup" ]] && rm -f "$apache_conf_backup"
  rm -rf "$preserve_dir"
  exit "$status"
}
trap installer_cleanup EXIT

if [[ ! -f "$CONFIG_FILE" ]]; then
  install -o root -g "$WEB_GROUP" -m 0640 "$CONFIG_EXAMPLE" "$CONFIG_FILE"
  detected="$(detect_node || true)"
  if [[ "$detected" =~ ^[0-9]{4,7}$ ]]; then
    set_key MYNODE "\"$detected\"" "$CONFIG_FILE"
  fi
fi

# Remove the obsolete development-package checksum artifact if an older overlay left it behind.
rm -f "$APP_DIR/SHA256SUMS"

# Keep source code read-only to the web server. Runtime paths are handled below.
find "$APP_DIR" \
  -path "$APP_DIR/.git" -prune -o \
  -path "$APP_DIR/run" -prune -o \
  -path "$APP_DIR/cache" -prune -o \
  -path "$APP_DIR/logs" -prune -o \
  -path "$APP_DIR/data" -prune -o \
  -path "$CONFIG_FILE" -prune -o \
  -type d -exec chown root:root {} + -exec chmod 0755 {} +
find "$APP_DIR" \
  -path "$APP_DIR/.git" -prune -o \
  -path "$APP_DIR/run" -prune -o \
  -path "$APP_DIR/cache" -prune -o \
  -path "$APP_DIR/logs" -prune -o \
  -path "$APP_DIR/data" -prune -o \
  -path "$CONFIG_FILE" -prune -o \
  -type f -exec chown root:root {} + -exec chmod 0644 {} +
chmod 0755 "$APP_DIR/setup_allstar_connect.sh" "$HELPER" "$CONTROL_HELPER"
chown root:root "$APP_DIR/setup_allstar_connect.sh" "$HELPER" "$CONTROL_HELPER"

install -d -o www-data -g www-data -m 0750 "$APP_DIR/run"
install -d -o www-data -g www-data -m 0750 "$APP_DIR/cache"
install -d -o www-data -g www-data -m 0750 "$APP_DIR/cache/stats"
install -d -o www-data -g www-data -m 0750 "$APP_DIR/cache/echolink"
install -d -o www-data -g www-data -m 0750 "$APP_DIR/data"
find "$APP_DIR/run" "$APP_DIR/cache" -type d -exec chown www-data:www-data {} + -exec chmod 0750 {} +
find "$APP_DIR/run" "$APP_DIR/cache" -type f -exec chown www-data:www-data {} + -exec chmod 0640 {} + 2>/dev/null || true

# Keep tracked examples read-only while Favorites remains writable.
find "$APP_DIR/data" -maxdepth 1 -type f ! -name 'favorites.txt' ! -name 'dtmf_favorites.txt' -exec chown root:root {} + -exec chmod 0644 {} +
if [[ ! -f "$FAVORITES_FILE" ]]; then
  install -o www-data -g www-data -m 0640 /dev/null "$FAVORITES_FILE"
else
  chown www-data:www-data "$FAVORITES_FILE"
  chmod 0640 "$FAVORITES_FILE"
fi
if [[ ! -f "$DTMF_FAVORITES_FILE" ]]; then
  install -o www-data -g www-data -m 0640 "$DTMF_FAVORITES_EXAMPLE" "$DTMF_FAVORITES_FILE"
else
  chown www-data:www-data "$DTMF_FAVORITES_FILE"
  chmod 0640 "$DTMF_FAVORITES_FILE"
fi
chown root:"$WEB_GROUP" "$CONFIG_FILE"
chmod 0640 "$CONFIG_FILE"

# Use the system log directory. Migrate the old in-project activity log once.
install -d -o www-data -g adm -m 2750 "$LOG_DIR"
old_activity="$APP_DIR/logs/activity.jsonl"
if [[ -f "$old_activity" ]]; then
  if [[ -s "$ACTIVITY_LOG" ]]; then
    cat "$old_activity" >> "$ACTIVITY_LOG"
  else
    install -o www-data -g adm -m 0640 "$old_activity" "$ACTIVITY_LOG"
  fi
  rm -f "$old_activity"
fi
if [[ -f "$ACTIVITY_LOG" ]]; then
  chown www-data:adm "$ACTIVITY_LOG"
  chmod 0640 "$ACTIVITY_LOG"
fi
rmdir "$APP_DIR/logs" 2>/dev/null || true

sudo_tmp="$(mktemp)"
cat > "$sudo_tmp" <<SUDOERS
www-data ALL=(root) NOPASSWD: $HELPER iax-channels
www-data ALL=(root) NOPASSWD: $HELPER core-channels
www-data ALL=(root) NOPASSWD: $HELPER echolink-name *
www-data ALL=(root) NOPASSWD: $HELPER rpt-lstats *
www-data ALL=(root) NOPASSWD: $CONTROL_HELPER *
SUDOERS
chmod 0440 "$sudo_tmp"
visudo -cf "$sudo_tmp" >/dev/null || fail "Generated sudoers rules failed validation."
install -o root -g root -m 0440 "$sudo_tmp" "$SUDOERS_FILE"
rm -f "$sudo_tmp"

apache_sites_backup="$(mktemp)"
apache_conf_backup="$(mktemp)"
had_apache_conf=0
was_apache_conf_enabled=0
if [[ -f "$APACHE_CONF" ]]; then
  cp -a "$APACHE_CONF" "$apache_conf_backup"
  had_apache_conf=1
fi
if [[ -L "/etc/apache2/conf-enabled/${APACHE_CONF_NAME}.conf" ]]; then
  was_apache_conf_enabled=1
fi
tar -cpf "$apache_sites_backup" -C / etc/apache2/sites-available etc/apache2/sites-enabled

restore_apache() {
  tar -xpf "$apache_sites_backup" -C / >/dev/null 2>&1 || true
  if [[ "$had_apache_conf" == "1" ]]; then
    install -o root -g root -m 0644 "$apache_conf_backup" "$APACHE_CONF"
  else
    rm -f "$APACHE_CONF"
  fi
  if [[ "$was_apache_conf_enabled" == "1" ]]; then
    a2enconf "$APACHE_CONF_NAME" >/dev/null 2>&1 || true
  else
    a2disconf "$APACHE_CONF_NAME" >/dev/null 2>&1 || true
  fi
}

cleanup_apache_backups() {
  rm -f "$apache_sites_backup" "$apache_conf_backup"
  apache_sites_backup=""
  apache_conf_backup=""
}

apache_tmp="$(mktemp)"
cat > "$apache_tmp" <<APACHE
<Directory "$APP_DIR">
    Options -Indexes
    AllowOverride None
    Require all denied
</Directory>

<Directory "$APP_DIR/public">
    Options -Indexes
    AllowOverride None
    Require all granted
    DirectoryIndex index.php
</Directory>

<Directory "$APP_DIR/api">
    Options -Indexes
    AllowOverride None
    Require all granted
</Directory>
APACHE
install -o root -g root -m 0644 "$apache_tmp" "$APACHE_CONF"
rm -f "$apache_tmp"
a2enconf "$APACHE_CONF_NAME" >/dev/null

# Suppress only successful high-frequency polling requests from access.log.
# Errors and all non-polling requests remain logged.
if ! php <<'PHP'
<?php
$condition = '(%{REQUEST_STATUS} == 200) && (%{REQUEST_URI} =~ m#^/allstar_connect/api/(local|downstream|echolink)\.php$#)';
$legacyAllTune = '%{REQUEST_URI} =~ m#^/alltune2/(api/status\.php|public/alltune2_ribbon_bar\.php)#';
$paths = glob('/etc/apache2/sites-enabled/*.conf') ?: [];
$seen = [];
$handled = false;
$changedPaths = [];
$skippedPaths = [];

foreach ($paths as $path) {
    $real = realpath($path);
    if ($real === false || isset($seen[$real])) continue;
    $seen[$real] = true;
    $lines = file($real);
    if ($lines === false) throw new RuntimeException("Unable to read $real");
    $changed = false;

    foreach ($lines as &$line) {
        $eol = str_ends_with($line, "\n") ? "\n" : '';
        $text = rtrim($line, "\r\n");
        if (!str_contains($text, 'CustomLog') || !str_contains($text, '${APACHE_LOG_DIR}/access.log')) continue;

        $normalized = str_replace('\\', '', $text);
        if (str_contains($normalized, '/allstar_connect/api/')
            || str_contains($normalized, '(allstar_view|allstar_connect)/api/')) {
            $handled = true;
            continue;
        }

        if (preg_match('/^(\s*CustomLog\s+\$\{APACHE_LOG_DIR\}\/access\.log\s+combined)\s*$/', $text, $m)) {
            $line = $m[1] . ' "expr=!((' . $condition . '))"' . $eol;
        } elseif (preg_match('/^(\s*CustomLog\s+\$\{APACHE_LOG_DIR\}\/access\.log\s+combined)\s+env=!dontlog_alltune2_polling\s*$/', $text, $m)) {
            $line = $m[1] . ' "expr=((!(' . $legacyAllTune . ')) && !((' . $condition . ')))"' . $eol;
        } elseif (preg_match('/^(\s*CustomLog\s+\$\{APACHE_LOG_DIR\}\/access\.log\s+combined)\s+"expr=(.*)"\s*$/', $text, $m)) {
            $line = $m[1] . ' "expr=((' . $m[2] . ') && !((' . $condition . ')))"' . $eol;
        } else {
            $skippedPaths[$real] = true;
            continue;
        }

        $changed = true;
        $handled = true;
    }
    unset($line);

    if ($changed) {
        if (file_put_contents($real, implode('', $lines), LOCK_EX) === false) {
            throw new RuntimeException("Unable to update $real");
        }
        $changedPaths[] = $real;
    }
}

foreach (array_keys($skippedPaths) as $path) {
    fwrite(STDERR, "[WARN] Unsupported Apache access.log line left unchanged in $path.\n");
}
if (!$handled) {
    fwrite(STDERR, "[WARN] No supported enabled Apache access.log line was found; polling log filtering was not installed.\n");
}
foreach ($changedPaths as $path) {
    fwrite(STDOUT, "[OK] Filtered polling access log in: $path\n");
}
PHP
then
  restore_apache
  fail "Unable to install the Apache polling access-log filter; previous Apache files were restored."
fi

if ! apache2ctl configtest >/dev/null; then
  restore_apache
  apache2ctl configtest >/dev/null 2>&1 || true
  systemctl reload apache2.service >/dev/null 2>&1 || true
  fail "Apache rejected the AllStar Connect configuration; previous Apache files were restored."
fi
if ! systemctl reload apache2.service >/dev/null; then
  restore_apache
  apache2ctl configtest >/dev/null 2>&1 || true
  systemctl reload apache2.service >/dev/null 2>&1 || true
  fail "Apache reload failed; previous Apache files were restored."
fi
cleanup_apache_backups

logrotate_tmp="$(mktemp)"
cat > "$logrotate_tmp" <<LOGROTATE
$LOG_DIR/*.log {
    daily
    rotate 1
    maxage 1
    maxsize 5M
    nocompress
    missingok
    notifempty
    create 0640 www-data adm
    su www-data adm
}
LOGROTATE
if ! logrotate -d "$logrotate_tmp" >/dev/null 2>&1; then
  rm -f "$logrotate_tmp"
  fail "The AllStar Connect logrotate configuration failed validation."
fi
install -o root -g root -m 0644 "$logrotate_tmp" "$LOGROTATE_FILE"
rm -f "$logrotate_tmp"

# Existing local files must remain byte-for-byte unchanged during normal setup.
if [[ "$had_config" == "1" ]] && ! cmp -s "$preserve_dir/config.ini" "$CONFIG_FILE"; then
  install -o root -g "$WEB_GROUP" -m 0640 "$preserve_dir/config.ini" "$CONFIG_FILE"
  fail "config.ini changed unexpectedly and was restored."
fi
if [[ "$had_favorites" == "1" ]] && ! cmp -s "$preserve_dir/favorites.txt" "$FAVORITES_FILE"; then
  install -o www-data -g www-data -m 0640 "$preserve_dir/favorites.txt" "$FAVORITES_FILE"
  fail "favorites.txt changed unexpectedly and was restored."
fi
if [[ "$had_dtmf_favorites" == "1" ]] && ! cmp -s "$preserve_dir/dtmf_favorites.txt" "$DTMF_FAVORITES_FILE"; then
  install -o www-data -g www-data -m 0640 "$preserve_dir/dtmf_favorites.txt" "$DTMF_FAVORITES_FILE"
  fail "dtmf_favorites.txt changed unexpectedly and was restored."
fi

for writable in "$APP_DIR/run" "$APP_DIR/cache" "$APP_DIR/cache/stats" "$APP_DIR/cache/echolink" "$APP_DIR/data" "$LOG_DIR"; do
  sudo -u "$WEB_USER" test -w "$writable" || fail "$writable is not writable by $WEB_USER"
done
sudo -u "$WEB_USER" test ! -w "$APP_DIR/public" || fail "$APP_DIR/public must not be writable by $WEB_USER"
sudo -u "$WEB_USER" test ! -w "$HELPER" || fail "$HELPER must not be writable by $WEB_USER"
sudo -u "$WEB_USER" test ! -w "$CONTROL_HELPER" || fail "$CONTROL_HELPER must not be writable by $WEB_USER"

node="$(get_key MYNODE "$CONFIG_FILE")"
trap - EXIT
cleanup_apache_backups
rm -rf "$preserve_dir"

echo "[OK] AllStar Connect setup complete."
if [[ "$node" =~ ^[0-9]{4,7}$ ]]; then
  echo "Local node: $node"
else
  echo "MYNODE was not detected unambiguously."
  echo "Set MYNODE in $CONFIG_FILE, then run this installer again."
fi
echo "URL: http://YOUR-NODE-IP-ADDRESS/allstar_connect/public/"
echo "Config: $CONFIG_FILE"
echo "Favorites: $FAVORITES_FILE"
echo "Logs: $LOG_DIR"
show_auth_status
