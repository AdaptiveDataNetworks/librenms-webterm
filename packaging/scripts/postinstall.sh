#!/bin/sh
set -e

. /usr/share/librenms-webterm/lifecycle.sh 2>/dev/null || . "$(dirname "$0")/lifecycle.sh"

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

if webterm_have_systemd; then
    systemctl daemon-reload >/dev/null 2>&1 || true
fi

# deb only. rpm restores this from %posttrans, which is the only hook that runs
# after the outgoing package's %preun; dpkg has no equivalent, so the restore
# happens here instead.
#
# Two sources, in order of trust:
#   1. A state file, written by a prerm of ours. That is a fact.
#   2. Failing that, the outgoing version. Releases up to 1.0.9 ran
#      `systemctl stop` and `systemctl disable` from their prerm with no
#      argument guard, and never wrote a state file -- so for those, and only
#      those, assume the gateway was meant to be running. Measured against the
#      real 1.0.9 deb: without this the upgrade ends inactive and disabled and
#      the operator's terminal simply stops working.
#
# The state file is what stops this depending on version arithmetic forever. A
# snapshot build is versioned 1.0.9~SNAPSHOT-<sha>, which sorts BELOW 1.0.9, so
# the version test alone would restart a gateway a dev-channel tester had
# deliberately stopped, on every single upgrade.
WEBTERM_UPGRADE_STATE=/run/librenms-webterm-gw.upgrade-state
if [ "${1:-}" = configure ] && [ -n "${2:-}" ] && webterm_have_systemd; then
    # ORDER MATTERS. The version test comes first because it is the only
    # unambiguous signal: releases up to 1.0.9 never wrote a state file, so any
    # file present during THAT hop is a leftover from some earlier transaction
    # and describes the wrong moment. Trusting it restored "stopped" over a
    # gateway that had been running, which is the exact failure this whole
    # section exists to prevent.
    if command -v dpkg >/dev/null 2>&1 && dpkg --compare-versions "$2" le "1.0.9"; then
        rm -f "$WEBTERM_UPGRADE_STATE" 2>/dev/null || true
        systemctl enable librenms-webterm-gw >/dev/null 2>&1 || true
        systemctl start librenms-webterm-gw >/dev/null 2>&1 || true
        echo "Re-enabled and started the gateway: $2's package scripts stopped and"
        echo "disabled it on upgrade, which was a bug in that release, not a choice."
        echo "Check both halves agree:  su - librenms -c 'cd /opt/librenms && ./lnms webterm:doctor'"
        exit 0
    fi
    # From 1.1.0 onward the outgoing package writes this from its own prerm, so
    # the state is a fact rather than an inference from a version string. That
    # matters for snapshot builds, which are versioned 1.0.9~SNAPSHOT-<sha> and
    # sort BELOW 1.0.9 -- the version test alone would restart a gateway a
    # dev-channel tester had deliberately stopped, on every upgrade.
    if [ -r "$WEBTERM_UPGRADE_STATE" ]; then
        webterm_was_enabled=0
        webterm_was_active=0
        . "$WEBTERM_UPGRADE_STATE"
        rm -f "$WEBTERM_UPGRADE_STATE" 2>/dev/null || true
        if [ "$webterm_was_enabled" = 1 ]; then
            systemctl enable librenms-webterm-gw >/dev/null 2>&1 || true
        fi
        if [ "$webterm_was_active" = 1 ]; then
            systemctl start librenms-webterm-gw >/dev/null 2>&1 || true
        fi
        echo "Upgraded. The gateway was restored to the state it was in before."
        echo "Check both halves agree:  su - librenms -c 'cd /opt/librenms && ./lnms webterm:doctor'"
        exit 0
    fi
fi

if webterm_is_upgrade_install "${1:-}" "${2:-}"; then
    # try-restart, not restart: it starts nothing that was not already running,
    # so an operator who deliberately keeps the gateway stopped stays stopped.
    #
    # Without this the upgrade replaced /usr/bin/librenms-webterm-gw while the
    # old process kept serving -- so the new binary delivered nothing on the
    # day and then took effect at an unrelated reboot weeks later, with the
    # cause long out of anyone's scrollback.
    if webterm_have_systemd; then
        systemctl try-restart librenms-webterm-gw >/dev/null 2>&1 || true
    fi

    echo "Upgraded. The running gateway was restarted if it was up."
    echo "Check both halves still agree:  su - librenms -c 'cd /opt/librenms && ./lnms webterm:doctor'"
    exit 0
fi

echo "Set WEBTERM_ALLOWED_ORIGINS in /etc/librenms-webterm/gateway.env before starting."
echo "Then: systemctl enable --now librenms-webterm-gw"
