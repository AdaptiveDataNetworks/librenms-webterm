#!/bin/sh
set -e

. /usr/share/librenms-webterm/lifecycle.sh 2>/dev/null || . "$(dirname "$0")/lifecycle.sh"

# On an upgrade, record whether the gateway was enabled and running before
# handing over. dpkg runs THIS script (the outgoing package's prerm) before
# anything belonging to the incoming one, so it is the only place on deb where
# that truth is still readable -- postinstall.sh reads it back. rpm captures the
# same thing from the incoming %pre instead, which runs earlier still.
#
# Doing it here also means the next upgrade stops depending on version
# arithmetic: a state file is a fact, whereas "was the outgoing version older
# than the fix" is a guess that a snapshot version string gets wrong.
if ! webterm_is_final_removal "${1:-}" && [ -d /run/systemd/system ] \
   && command -v systemctl >/dev/null 2>&1; then
    if systemctl is-enabled --quiet librenms-webterm-gw 2>/dev/null; then
        _e=1
    else
        _e=0
    fi
    if systemctl is-active --quiet librenms-webterm-gw 2>/dev/null; then
        _a=1
    else
        _a=0
    fi
    printf 'webterm_was_enabled=%s\nwebterm_was_active=%s\n' "$_e" "$_a" \
        > /run/librenms-webterm-gw.upgrade-state 2>/dev/null || true
fi

# Do NOTHING else on an upgrade. On rpm the old package's %preun runs after the new
# package's %post, so stopping here would leave the freshly-installed gateway
# down; disabling here left it disabled across a reboot too. On Debian the
# ordering differs but the outcome was the same.
if ! webterm_is_final_removal "${1:-}"; then
    exit 0
fi

if webterm_have_systemd; then
    systemctl stop librenms-webterm-gw >/dev/null 2>&1 || true
    systemctl disable librenms-webterm-gw >/dev/null 2>&1 || true
fi

# The secret and the user are deliberately left behind: removing them would
# silently break a reinstall, and an operator who wants them gone can say so.
