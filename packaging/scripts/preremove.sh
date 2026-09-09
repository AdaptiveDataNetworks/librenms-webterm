#!/bin/sh
set -e

. /usr/share/librenms-webterm/lifecycle.sh 2>/dev/null || . "$(dirname "$0")/lifecycle.sh"

# Do NOTHING on an upgrade. On rpm the old package's %preun runs after the new
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
