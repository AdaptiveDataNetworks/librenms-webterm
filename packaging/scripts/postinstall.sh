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
    echo "  (the plugin already defaults to /etc/librenms-webterm/gateway.secret -- nothing to set)"
    echo ""
fi

# LibreNMS must be able to read the secret. Doing it here removes the most
# common way this install stalls: the gateway runs, LibreNMS cannot read the
# secret, and the failure is not obviously a permissions problem.
if getent passwd librenms >/dev/null 2>&1; then
    if ! id -nG librenms 2>/dev/null | tr ' ' '\n' | grep -qx librenms-webterm; then
        usermod -a -G librenms-webterm librenms
        echo "Added the librenms user to the librenms-webterm group."
        echo "Restart php-fpm so it picks up the new group."
    fi
else
    echo "No 'librenms' account found. Once you know which account LibreNMS runs as:"
    echo "  sudo usermod -a -G librenms-webterm <that-user> && systemctl restart php-fpm"
fi

if [ -d /run/systemd/system ]; then
    systemctl daemon-reload >/dev/null 2>&1 || true
fi

echo "Set WEBTERM_ALLOWED_ORIGINS in /etc/librenms-webterm/gateway.env before starting."
echo "Then: systemctl enable --now librenms-webterm-gw"
