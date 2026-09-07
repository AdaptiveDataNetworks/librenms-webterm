#!/bin/sh
#
# Install the LibreNMS WebTerm gateway.
#
# Deliberately NOT designed for `curl | sh`. It requires an explicit --version,
# verifies a checksum, and prints what it is about to do -- because piping an
# unread script from the internet into a root shell, to install a component
# whose whole job is holding SSH sessions to your network, is not a defensible
# way to deploy this.
#
# Download it, read it, then run it.
#
set -eu

REPO="adaptivedatanetworks/librenms-webterm"
VERSION=""
PREFIX="/usr/bin"
CONFDIR="/etc/librenms-webterm"
DRY_RUN=0

usage() {
    cat <<USAGE
Usage: sh install.sh --version vX.Y.Z [--prefix /usr/bin] [--dry-run]

  --version   Required. The release to install, e.g. v1.0.0
  --prefix    Where to install the binary (default: /usr/bin)
  --dry-run   Print what would happen and stop

Prefer your distribution package if there is one:
  https://github.com/$REPO/releases
USAGE
}

while [ $# -gt 0 ]; do
    case "$1" in
        --version) VERSION="${2:-}"; shift 2 ;;
        --prefix)  PREFIX="${2:-}"; shift 2 ;;
        --dry-run) DRY_RUN=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown option: $1" >&2; usage; exit 2 ;;
    esac
done

if [ -z "$VERSION" ]; then
    echo "error: --version is required." >&2
    echo "Pinning the version is deliberate: an unpinned install cannot be reproduced," >&2
    echo "and this component versions independently of the LibreNMS plugin." >&2
    exit 2
fi

if [ -f /etc/debian_version ] && dpkg -s librenms-webterm-gw >/dev/null 2>&1; then
    echo "error: librenms-webterm-gw is installed from a package." >&2
    echo "Upgrade with apt instead; installing over it would leave two copies." >&2
    exit 1
fi
if command -v rpm >/dev/null 2>&1 && rpm -q librenms-webterm-gw >/dev/null 2>&1; then
    echo "error: librenms-webterm-gw is installed from a package. Upgrade with your package manager." >&2
    exit 1
fi

case "$(uname -m)" in
    x86_64|amd64) ARCH="amd64" ;;
    aarch64|arm64) ARCH="arm64" ;;
    *) echo "error: unsupported architecture $(uname -m). Only amd64 and arm64 are built." >&2; exit 1 ;;
esac

TARBALL="librenms-webterm-gw_${VERSION#v}_linux_${ARCH}.tar.gz"
BASE="https://github.com/$REPO/releases/download/$VERSION"

echo "This will:"
echo "  * download $BASE/$TARBALL"
echo "  * verify it against checksums.txt from the same release"
echo "  * install librenms-webterm-gw to $PREFIX"
echo "  * create the librenms-webterm system user and $CONFDIR"
echo "  * install a systemd unit (it will NOT be started)"
echo ""

if [ "$DRY_RUN" -eq 1 ]; then
    echo "Dry run: stopping here."
    exit 0
fi

if [ "$(id -u)" -ne 0 ]; then
    echo "error: run this as root." >&2
    exit 1
fi

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "Downloading..."
curl -fsSL -o "$TMP/$TARBALL" "$BASE/$TARBALL"
curl -fsSL -o "$TMP/checksums.txt" "$BASE/checksums.txt"

echo "Verifying checksum..."
( cd "$TMP" && grep " $TARBALL\$" checksums.txt | sha256sum -c - ) || {
    echo "error: checksum verification FAILED. Not installing." >&2
    exit 1
}

echo "If you have the GitHub CLI, you can also verify build provenance:"
echo "  gh attestation verify $TMP/$TARBALL --repo $REPO"
echo ""

tar -xzf "$TMP/$TARBALL" -C "$TMP"
install -m 0755 "$TMP/librenms-webterm-gw" "$PREFIX/librenms-webterm-gw"

getent group librenms-webterm >/dev/null 2>&1 || groupadd --system librenms-webterm
getent passwd librenms-webterm >/dev/null 2>&1 || useradd --system \
    --gid librenms-webterm --home-dir /nonexistent --no-create-home \
    --shell /usr/sbin/nologin --comment "LibreNMS WebTerm gateway" librenms-webterm

install -d -o root -g librenms-webterm -m 0750 "$CONFDIR"

if [ ! -f "$CONFDIR/gateway.secret" ]; then
    "$PREFIX/librenms-webterm-gw" init --path "$CONFDIR/gateway.secret" >/dev/null
    chown root:librenms-webterm "$CONFDIR/gateway.secret"
    chmod 0640 "$CONFDIR/gateway.secret"
    echo "Generated a shared secret at $CONFDIR/gateway.secret"
fi

if [ ! -f "$CONFDIR/gateway.env" ]; then
    if [ ! -f "$TMP/packaging/systemd/librenms-webterm-gw.env.example" ]; then
        # Do not skip silently. Without gateway.env the gateway starts with no
        # allowed origins, refuses every browser connection, and leaves the
        # operator with no file to edit and no clue why.
        echo "error: the release tarball is missing packaging/systemd/librenms-webterm-gw.env.example" >&2
        echo "This is a packaging bug; please report it at https://github.com/$REPO/issues" >&2
        exit 1
    fi

    install -m 0640 -o root -g librenms-webterm \
        "$TMP/packaging/systemd/librenms-webterm-gw.env.example" "$CONFDIR/gateway.env"
    echo "Wrote $CONFDIR/gateway.env"
fi

if [ -d /lib/systemd/system ] && [ -f "$TMP/packaging/systemd/librenms-webterm-gw.service" ]; then
    install -m 0644 "$TMP/packaging/systemd/librenms-webterm-gw.service" \
        /lib/systemd/system/librenms-webterm-gw.service
    systemctl daemon-reload || true
fi

cat <<NEXT

Installed $("$PREFIX/librenms-webterm-gw" version)

Before starting it:

  1. Set your LibreNMS origin in $CONFDIR/gateway.env
       WEBTERM_ALLOWED_ORIGINS=https://librenms.example.com
     Without this every browser connection is refused.

  2. Give LibreNMS read access to the secret, then point the plugin at it:
       su - librenms
       ./lnms webterm:config set gateway.secret_file $CONFDIR/gateway.secret

  3. Start it:
       systemctl enable --now librenms-webterm-gw

  4. Check both halves agree:
       ./lnms webterm:doctor

Documentation: https://adaptivedatanetworks.github.io/librenms-webterm/
NEXT
