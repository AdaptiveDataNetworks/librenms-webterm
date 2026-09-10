#!/bin/sh
# The installer edits a file the operator wrote and that their web server must
# keep parsing. That is the highest-consequence thing in this repo outside the
# credential path, so it gets its own test: real vhost shapes, a file with no
# server block at all, and a second run over the first run's output.
set -eu

cd "$(dirname "$0")/.."
T=$(mktemp -d)
trap 'rm -rf "$T"' EXIT
fails=0
ok()   { echo "  ok: $1"; }
bad()  { echo "  FAIL: $1" >&2; fails=$((fails + 1)); }

# Stand-ins for the parts that need a running web server. The insertion itself
# is what is under test.
say() { printf '%s\n' "$*"; }
step() { :; }
warn() { printf '  (warn) %s\n' "$*"; }
have() { command -v "$1" >/dev/null 2>&1; }
ask() { printf '%s' "$2"; }
confirm() { [ "${2:-}" != no ]; }

WEBTERM_SNIPPET_DIR="$T"
export WEBTERM_SNIPPET_DIR
. packaging/webserver.sh

webterm_already_proxied() { return 1; }
webterm_config_test() { [ "${STUB_CONFIGTEST:-0}" = 0 ]; }
webterm_reload() { return 0; }
webterm_candidate_vhosts() { echo "$VHOST"; }

cat > "$T/librenms.nginx" <<'EOF'
server {
    listen 80;
    server_name librenms.example.com;
    root /opt/librenms/html;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    location ~ \.php$ {
        include fastcgi.conf;
        fastcgi_pass unix:/run/php-fpm/librenms.sock;
    }
}
EOF
cat > "$T/librenms.apache" <<'EOF'
<VirtualHost *:80>
  DocumentRoot /opt/librenms/html/
  ServerName librenms.example.com
  <Directory "/opt/librenms/html/">
    Require all granted
    AllowOverride All
  </Directory>
</VirtualHost>
EOF
printf 'user nginx;\nworker_processes auto;\n' > "$T/no-server-block.conf"

# ---- nginx: insert, then re-run ----
WEBSERVER=nginx; VHOST="$T/librenms.nginx"; CONFIGURE_WEBSERVER=yes
sed -i 's#^\(.*\)$#\1#' "$T/librenms.nginx"
_orig=$(cat "$VHOST")
webterm_configure_webserver >/dev/null

grep -q 'include .*webterm-proxy.conf;' "$VHOST" && ok "nginx: include line added" || bad "nginx: no include line"
grep -q 'librenms-webterm (managed)' "$VHOST" && ok "nginx: marker present for later removal" || bad "nginx: no marker"
# Everything the operator wrote must still be there, byte for byte.
if printf '%s\n' "$_orig" | while IFS= read -r line; do grep -qxF "$line" "$VHOST" || exit 1; done; then
    ok "nginx: every original line preserved"
else
    bad "nginx: original config was altered"
fi
# The include must land INSIDE the server block, not before it.
if [ "$(grep -n 'server[[:space:]]*{' "$VHOST" | head -1 | cut -d: -f1)" -lt \
     "$(grep -n 'include .*webterm-proxy.conf;' "$VHOST" | head -1 | cut -d: -f1)" ]; then
    ok "nginx: include is inside the server block"
else
    bad "nginx: include landed outside the server block"
fi

_after=$(cat "$VHOST")
webterm_configure_webserver >/dev/null
[ "$_after" = "$(cat "$VHOST")" ] && ok "nginx: re-running changes nothing" || bad "nginx: not idempotent"

# ---- apache ----
WEBSERVER=apache; VHOST="$T/librenms.apache"
webterm_configure_webserver >/dev/null
grep -q 'Include .*webterm-proxy.conf' "$VHOST" && ok "apache: Include line added" || bad "apache: no Include line"
if [ "$(grep -n '<VirtualHost' "$VHOST" | head -1 | cut -d: -f1)" -lt \
     "$(grep -n 'Include .*webterm-proxy.conf' "$VHOST" | head -1 | cut -d: -f1)" ]; then
    ok "apache: Include is inside the VirtualHost"
else
    bad "apache: Include landed outside the VirtualHost"
fi

# ---- the common production shape: :80 redirect, then the real :443 vhost ----
# Getting this wrong puts the proxy in the block that only issues a 301, which
# installs cleanly and then 404s every terminal.
WEBSERVER=nginx; VHOST="$T/redirect-pair.nginx"
cat > "$VHOST" <<'EOF'
server {
    listen 80;
    server_name librenms.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name librenms.example.com;
    root /opt/librenms/html;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php-fpm/librenms.sock;
    }
}
EOF
webterm_configure_webserver >/dev/null
_inc=$(grep -n 'include .*webterm-proxy.conf;' "$VHOST" | head -1 | cut -d: -f1)
_ssl=$(grep -n 'listen 443' "$VHOST" | head -1 | cut -d: -f1)
_r301=$(grep -n 'return 301' "$VHOST" | head -1 | cut -d: -f1)
if [ -n "$_inc" ] && [ "$_inc" -gt "$_r301" ] && [ "$_inc" -lt "$_ssl" ]; then
    ok "redirect pair: proxy went into the :443 block, not the :80 redirect"
else
    bad "redirect pair: proxy landed in the wrong server block (line $_inc)"
fi

# ---- same for apache ----
WEBSERVER=apache; VHOST="$T/redirect-pair.apache"
cat > "$VHOST" <<'EOF'
<VirtualHost *:80>
  ServerName librenms.example.com
  Redirect permanent / https://librenms.example.com/
</VirtualHost>

<VirtualHost *:443>
  ServerName librenms.example.com
  DocumentRoot /opt/librenms/html/
</VirtualHost>
EOF
webterm_configure_webserver >/dev/null
_inc=$(grep -n 'Include .*webterm-proxy.conf' "$VHOST" | head -1 | cut -d: -f1)
_443=$(grep -n '<VirtualHost \*:443>' "$VHOST" | head -1 | cut -d: -f1)
if [ -n "$_inc" ] && [ "$_inc" -gt "$_443" ]; then
    ok "apache redirect pair: proxy went into the :443 vhost"
else
    bad "apache redirect pair: proxy landed in the wrong vhost (line $_inc)"
fi

# ---- a server block written on ONE line ----
# Appending after the anchor line puts the include outside the block, and nginx
# rejects the whole file with "location directive is not allowed here".
WEBSERVER=nginx; VHOST="$T/oneline.nginx"
printf 'server { listen 80; server_name x; root /opt/librenms/html; }\n' > "$VHOST"
webterm_configure_webserver >/dev/null
_inc=$(grep -n 'include .*webterm-proxy.conf;' "$VHOST" | head -1 | cut -d: -f1)
_close=$(grep -n '^}' "$VHOST" | head -1 | cut -d: -f1)
if [ -n "$_inc" ] && { [ -z "$_close" ] || [ "$_inc" -lt "$_close" ]; }; then
    ok "one-line server block: include landed inside the braces"
else
    bad "one-line server block: include landed outside the block"
fi
grep -q 'listen 80' "$VHOST" && ok "one-line server block: the original directives survived" \
                             || bad "one-line server block: directives were lost"

# ---- a file with no server block: change nothing ----
WEBSERVER=nginx; VHOST="$T/no-server-block.conf"
_before=$(cat "$VHOST")
webterm_configure_webserver >/dev/null 2>&1
[ "$_before" = "$(cat "$VHOST")" ] && ok "no server block: file untouched" || bad "no server block: file was modified"

# ---- config test fails: must restore ----
WEBSERVER=nginx; VHOST="$T/rollback.nginx"
cp "$T/librenms.apache" "$VHOST"   # contents irrelevant; shape has no server {}
cat > "$VHOST" <<'EOF'
server {
    listen 80;
}
EOF
_before=$(cat "$VHOST")
STUB_CONFIGTEST=1 webterm_configure_webserver >/dev/null 2>&1
[ "$_before" = "$(cat "$VHOST")" ] && ok "rejected config: vhost restored exactly" || bad "rejected config: left the vhost modified"
ls "$T"/*.webterm-backup.* >/dev/null 2>&1 && bad "backup files left behind" || ok "no backup files left behind"

[ "$fails" -eq 0 ] || { echo "webserver-insert-check: $fails failure(s)" >&2; exit 1; }
echo "webserver-insert-check OK"
