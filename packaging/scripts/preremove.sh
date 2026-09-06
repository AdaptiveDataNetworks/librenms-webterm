#!/bin/sh
set -e

if [ -d /run/systemd/system ]; then
    systemctl stop librenms-webterm-gw >/dev/null 2>&1 || true
    systemctl disable librenms-webterm-gw >/dev/null 2>&1 || true
fi

# The secret and the user are deliberately left behind: removing them would
# silently break a reinstall, and an operator who wants them gone can say so.
