#!/bin/sh
set -e

install -d -o root -g librenms-webterm -m 0750 /etc/librenms-webterm

# Generate the shared secret only if absent. Regenerating it on every upgrade
# would break every install at the worst possible moment: LibreNMS would keep
# the old one and every session mint would fail with an opaque 401.
if [ ! -f /etc/librenms-webterm/gateway.secret ]; then
    /usr/bin/librenms-webterm-gw init --path /etc/librenms-webterm/gateway.secret >/dev/null
    chown root:librenms-webterm /etc/librenms-webterm/gateway.secret
    chmod 0640 /etc/librenms-webterm/gateway.secret

    echo ""
    echo "A shared secret was generated at /etc/librenms-webterm/gateway.secret"
    echo ""
    echo "Give LibreNMS read access to that file, then:"
    echo "  su - librenms"
    echo "  ./lnms webterm:config set gateway.secret_file /etc/librenms-webterm/gateway.secret"
    echo ""
fi

if [ -d /run/systemd/system ]; then
    systemctl daemon-reload >/dev/null 2>&1 || true
fi

echo "Set WEBTERM_ALLOWED_ORIGINS in /etc/librenms-webterm/gateway.env before starting."
echo "Then: systemctl enable --now librenms-webterm-gw"
