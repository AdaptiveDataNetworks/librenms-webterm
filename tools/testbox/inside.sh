#!/usr/bin/env bash
# Runs inside the test box. WS, DEB and RPM come from run.sh.
set -uo pipefail

fails=0
ok()  { echo "    ok: $1"; }
bad() { echo "    FAIL: $1" >&2; fails=$((fails + 1)); }

if command -v dpkg >/dev/null; then
    FAMILY=debian
    dpkg -i "$DEB" >/dev/null 2>&1 || { echo "package install failed" >&2; exit 1; }
else
    FAMILY=rhel
    rpm -i --nodeps "$RPM" >/dev/null 2>&1 || { echo "package install failed" >&2; exit 1; }
fi
echo "  package installed"

# ---------------------------------------------------------- a LibreNMS vhost --
if [ "$WS" = nginx ]; then
    VHOST=/etc/nginx/conf.d/librenms.conf
    cat > "$VHOST" <<'EOF'
server {
    listen 80 default_server;
    server_name localhost;
    root /opt/librenms/html;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
}
EOF
    SVC=nginx
else
    if [ "$FAMILY" = debian ]; then
        a2dissite 000-default >/dev/null 2>&1 || true
        VHOST=/etc/apache2/sites-enabled/librenms.conf
        SVC=apache2
    else
        VHOST=/etc/httpd/conf.d/librenms.conf
        SVC=httpd
    fi
    cat > "$VHOST" <<'EOF'
<VirtualHost *:80>
  ServerName localhost
  DocumentRoot /opt/librenms/html/
  <Directory "/opt/librenms/html/">
    Require all granted
  </Directory>
</VirtualHost>
EOF
fi
mkdir -p /opt/librenms/html && echo ok > /opt/librenms/html/index.html
systemctl enable --now "$SVC" >/dev/null 2>&1
systemctl is-active --quiet "$SVC" || { echo "$SVC would not start" >&2; exit 1; }
echo "  $SVC running"

# ------------------------------------------------------------- the installer --
echo "  running librenms-webterm-setup"
OUT=$(/usr/sbin/librenms-webterm-setup \
        --librenms-dir /opt/librenms --librenms-user librenms \
        --origin http://localhost --webserver "$WS" --vhost "$VHOST" \
        --configure-webserver --no-install-plugin --no-selinux -y 2>&1)
RC=$?
printf '%s\n' "$OUT" | sed 's/^/    | /'
[ "$RC" -eq 0 ] && ok "installer exited 0" || bad "installer exited $RC"

# ------------------------------------------------------------------ outcomes --
systemctl is-active --quiet librenms-webterm-gw \
    && ok "gateway unit is active" || bad "gateway unit is not active"

for _ in $(seq 1 20); do
    (exec 3<>/dev/tcp/127.0.0.1/8377) 2>/dev/null && break
    sleep 0.5
done
(exec 3<>/dev/tcp/127.0.0.1/8377) 2>/dev/null \
    && ok "gateway is listening on 127.0.0.1:8377" || bad "nothing listening on 8377"

grep -q 'librenms-webterm (managed)' "$VHOST" \
    && ok "proxy config added to $VHOST" || bad "vhost was not modified"

systemctl is-active --quiet "$SVC" \
    && ok "$SVC still running after the edit" || bad "$SVC died after the edit"

# The point of the whole exercise: a real upgrade through a real proxy.
# 101 means the request traversed nginx/Apache, reached the gateway, and the
# origin was accepted. Curl exits non-zero here -- that is the success path.
CODE=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 5 --http1.1 \
    -H 'Connection: Upgrade' -H 'Upgrade: websocket' \
    -H 'Sec-WebSocket-Version: 13' -H 'Sec-WebSocket-Key: AAAAAAAAAAAAAAAAAAAAAA==' \
    -H 'Sec-WebSocket-Protocol: lnms-webterm.v1' \
    -H 'Origin: http://localhost' http://localhost/webterm/ws 2>/dev/null)
[ "$CODE" = 101 ] && ok "WebSocket upgrade through $WS returned 101" \
                  || bad "WebSocket upgrade through $WS returned $CODE (want 101)"

# An origin the gateway was not told about must be refused, or the allow-list is
# not doing anything.
CODE=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 5 --http1.1 \
    -H 'Connection: Upgrade' -H 'Upgrade: websocket' \
    -H 'Sec-WebSocket-Version: 13' -H 'Sec-WebSocket-Key: AAAAAAAAAAAAAAAAAAAAAA==' \
    -H 'Origin: http://evil.example.net' http://localhost/webterm/ws 2>/dev/null)
[ "$CODE" = 403 ] && ok "a foreign origin is refused with 403" \
                  || bad "a foreign origin got $CODE (want 403)"

curl -fsS --max-time 5 http://localhost/webterm/ui/ >/dev/null 2>&1 \
    && ok "the xterm.js assets are served through the proxy" \
    || bad "/webterm/ui/ is not reachable through the proxy"

# ------------------------------------------------------------- re-run safety --
BEFORE=$(cat "$VHOST")
/usr/sbin/librenms-webterm-setup \
    --librenms-dir /opt/librenms --librenms-user librenms \
    --origin http://localhost --webserver "$WS" --vhost "$VHOST" \
    --configure-webserver --no-install-plugin --no-selinux -y >/dev/null 2>&1
RC=$?
[ "$RC" -eq 0 ] && ok "a second run exits 0" || bad "a second run exited $RC"
[ "$BEFORE" = "$(cat "$VHOST")" ] && ok "a second run leaves the vhost alone" \
                                  || bad "a second run modified the vhost again"
# Count only live settings -- the shipped example carries a commented one, so a
# naive grep -c would pass no matter what the installer did.
N=$(grep -c '^[[:space:]]*WEBTERM_ALLOWED_ORIGINS=' /etc/librenms-webterm/gateway.env)
[ "$N" = 1 ] && ok "exactly one live WEBTERM_ALLOWED_ORIGINS after two runs" \
             || bad "gateway.env has $N live WEBTERM_ALLOWED_ORIGINS lines (want 1)"
grep -q '^[[:space:]]*WEBTERM_ALLOWED_ORIGINS=http://localhost$' /etc/librenms-webterm/gateway.env \
    && ok "the origin the installer was given is the one that is set" \
    || bad "WEBTERM_ALLOWED_ORIGINS is not http://localhost"

# ------------------------------------------------------------------ upgrade --
# The bug this catches: a package upgrade that leaves the gateway stopped and
# disabled, which is what the deb/rpm argument mix-up used to do.
if [ "$FAMILY" = debian ]; then
    dpkg -i "$DEB" >/dev/null 2>&1
else
    rpm -U --nodeps --force "$RPM" >/dev/null 2>&1
fi
sleep 1
systemctl is-active --quiet librenms-webterm-gw \
    && ok "gateway still running after a package upgrade" \
    || bad "package upgrade left the gateway stopped"
systemctl is-enabled --quiet librenms-webterm-gw \
    && ok "gateway still enabled after a package upgrade" \
    || bad "package upgrade left the gateway disabled"

[ "$fails" -eq 0 ] || exit 1
