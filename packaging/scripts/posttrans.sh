#!/bin/sh
# rpm only, and deliberately self-contained for the same reason preinstall.sh is.
#
# %posttrans is the last hook in the transaction -- after the OUTGOING package's
# %preun and %postun -- and is therefore the only place that can undo what an
# old, unguarded %preun did to our unit. Upgrading from 1.0.9 left the gateway
# inactive and disabled; verified against the real published 1.0.9 rpm both
# before and after this script existed.
set -e

STATE=/run/librenms-webterm-gw.upgrade-state
[ -r "$STATE" ] || exit 0
[ -d /run/systemd/system ] || { rm -f "$STATE"; exit 0; }
command -v systemctl >/dev/null 2>&1 || { rm -f "$STATE"; exit 0; }

webterm_was_enabled=0
webterm_was_active=0
. "$STATE"
rm -f "$STATE" 2>/dev/null || true

if [ "$webterm_was_enabled" = 1 ]; then
    systemctl enable librenms-webterm-gw >/dev/null 2>&1 || true
fi
if [ "$webterm_was_active" = 1 ]; then
    systemctl start librenms-webterm-gw >/dev/null 2>&1 || true
fi
