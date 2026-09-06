#!/bin/sh
set -e

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
