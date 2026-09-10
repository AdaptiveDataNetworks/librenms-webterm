#!/bin/sh
set -e

# This is deliberately self-contained and does NOT source lifecycle.sh.
#
# At %pre the incoming package's files are not on disk yet, and the outgoing one
# is 1.0.9 or older, which never shipped lifecycle.sh at all -- so sourcing it
# here silently defines nothing and the state below is never recorded. That is
# exactly how the first version of this fix failed: the deb path worked, the rpm
# path still came out inactive and disabled, and nothing said why.
#
# Why record at all: releases up to 1.0.9 ran `systemctl stop` and
# `systemctl disable` from their preremove with no argument guard, and on an
# upgrade it is the OUTGOING package's script that runs. On rpm this %pre is the
# last moment the truth is still readable; posttrans.sh puts it back afterwards.
if [ -d /run/systemd/system ] && command -v systemctl >/dev/null 2>&1; then
    if systemctl is-enabled --quiet librenms-webterm-gw 2>/dev/null; then
        webterm_was_enabled=1
    else
        webterm_was_enabled=0
    fi
    if systemctl is-active --quiet librenms-webterm-gw 2>/dev/null; then
        webterm_was_active=1
    else
        webterm_was_active=0
    fi
    printf 'webterm_was_enabled=%s\nwebterm_was_active=%s\n' \
        "$webterm_was_enabled" "$webterm_was_active" > /run/librenms-webterm-gw.upgrade-state 2>/dev/null || true
fi

# A dedicated unprivileged account. The gateway needs no home, no shell and no
# group membership: it opens outbound TCP and reads one file.
if ! getent group librenms-webterm >/dev/null 2>&1; then
    groupadd --system librenms-webterm
fi

if ! getent passwd librenms-webterm >/dev/null 2>&1; then
    useradd --system \
        --gid librenms-webterm \
        --home-dir /nonexistent \
        --no-create-home \
        --shell /usr/sbin/nologin \
        --comment "LibreNMS WebTerm gateway" \
        librenms-webterm
fi
