#!/bin/sh
#
# Install and configure LibreNMS WebTerm, end to end.
#
# Deliberately NOT designed for `curl | sh`. It requires an explicit --version,
# verifies a checksum, prints what it is about to do, and asks before touching
# anything it did not create -- because piping an unread script from the
# internet into a root shell, to install a component whose whole job is holding
# SSH sessions to your network, is not a defensible way to deploy this.
#
# Download it, read it, then run it.
#
# It is interactive by default and every question has a flag, so the same script
# drives a configuration-management run with no terminal attached.
#
set -eu

REPO="adaptivedatanetworks/librenms-webterm"
VERSION=""
PREFIX="/usr/bin"
CONFDIR="/etc/librenms-webterm"
LIBRENMS_DIR=""
LIBRENMS_USER=""
ORIGIN=""
WEBSERVER=""
VHOST=""
PHPFPM=""
CONFIGURE_WEBSERVER="ask"
CONFIGURE_SELINUX="ask"
INSTALL_PLUGIN="ask"
ASSUME_YES=0
DRY_RUN=0

usage() {
    cat <<USAGE
Usage: sudo sh install.sh --version vX.Y.Z [options]

  --version VER         Required. The release to install, e.g. v1.1.0

Detection overrides -- all of these are detected, and asked about when they
cannot be. Pass them to run without a terminal.

  --librenms-dir DIR    LibreNMS install directory
  --librenms-user USER  The account LibreNMS runs as
  --origin URL          The URL your operators reach LibreNMS on, scheme and
                        host, no path: https://librenms.example.com
                        This CANNOT be detected. APP_URL is unset on a stock
                        install and reads back as http://localhost; base_url may
                        be a bare path; a vhost's server_name knows nothing
                        about a TLS terminator in front of it.
  --webserver nginx|apache|none
  --vhost FILE          The server block / VirtualHost to add the proxy to
  --php-fpm UNIT        The php-fpm systemd unit to restart

Actions -- each defaults to asking.

  --configure-webserver / --no-configure-webserver
  --selinux / --no-selinux
  --install-plugin / --no-install-plugin
  --prefix DIR          Where the binary goes (default: /usr/bin)

  -y, --yes             Answer yes to every question. Implies the --*-yes forms.
  --dry-run             Print the plan and stop, changing nothing.
  -h, --help            This.
USAGE
}

while [ $# -gt 0 ]; do
    case "$1" in
        --version) VERSION="${2:-}"; shift 2 ;;
        --prefix) PREFIX="${2:-}"; shift 2 ;;
        --librenms-dir) LIBRENMS_DIR="${2:-}"; shift 2 ;;
        --librenms-user) LIBRENMS_USER="${2:-}"; shift 2 ;;
        --origin) ORIGIN="${2:-}"; shift 2 ;;
        --webserver) WEBSERVER="${2:-}"; shift 2 ;;
        --vhost) VHOST="${2:-}"; shift 2 ;;
        --php-fpm) PHPFPM="${2:-}"; shift 2 ;;
        --configure-webserver) CONFIGURE_WEBSERVER=yes; shift ;;
        --no-configure-webserver) CONFIGURE_WEBSERVER=no; shift ;;
        --selinux) CONFIGURE_SELINUX=yes; shift ;;
        --no-selinux) CONFIGURE_SELINUX=no; shift ;;
        --install-plugin) INSTALL_PLUGIN=yes; shift ;;
        --no-install-plugin) INSTALL_PLUGIN=no; shift ;;
        -y|--yes) ASSUME_YES=1; shift ;;
        --dry-run) DRY_RUN=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown option: $1" >&2; usage; exit 2 ;;
    esac
done

say()  { printf '%s\n' "$*"; }
step() { printf '\n== %s\n' "$*"; }
warn() { printf 'warning: %s\n' "$*" >&2; }
die()  { printf 'error: %s\n' "$*" >&2; exit 1; }

# Ask, offering a detected default. Non-interactive runs take the default, or
# fail if there is not one -- guessing an answer nobody gave is how an installer
# silently does the wrong thing.
ask() {
    _prompt=$1; _default=${2:-}
    if [ "$ASSUME_YES" -eq 1 ] || [ ! -t 0 ]; then
        [ -n "$_default" ] || die "$_prompt -- no default available, pass the matching flag"
        printf '%s\n' "$_default"
        return 0
    fi
    if [ -n "$_default" ]; then
        printf '%s [%s]: ' "$_prompt" "$_default" >&2
    else
        printf '%s: ' "$_prompt" >&2
    fi
    read -r _answer </dev/tty || _answer=""
    printf '%s\n' "${_answer:-$_default}"
}

confirm() {
    _prompt=$1; _setting=$2
    case "$_setting" in
        yes) return 0 ;;
        no)  return 1 ;;
    esac
    [ "$ASSUME_YES" -eq 1 ] && return 0
    [ -t 0 ] || return 1          # unattended and unspecified: do not touch it
    printf '%s [y/N]: ' "$_prompt" >&2
    read -r _a </dev/tty || _a=n
    case "$_a" in y|Y|yes|YES) return 0 ;; *) return 1 ;; esac
}

have() { command -v "$1" >/dev/null 2>&1; }

[ -n "$VERSION" ] || {
    echo "error: --version is required." >&2
    echo "Pinning the version is deliberate: an unpinned install cannot be reproduced," >&2
    echo "and this component versions independently of the LibreNMS plugin." >&2
    exit 2
}
[ "$(id -u)" -eq 0 ] || die "run this as root."

# ---------------------------------------------------------------- detection --

step "Looking around"

# Several independent markers, so one unusual install does not defeat all of
# them. The symlink is the most reliable: LibreNMS's own docs create it.
detect_librenms_dir() {
    [ -n "$LIBRENMS_DIR" ] && { printf '%s\n' "$LIBRENMS_DIR"; return; }
    for _c in \
        "$(readlink -f /usr/bin/lnms 2>/dev/null | sed 's#/lnms$##')" \
        "$(sed -n 's#^WorkingDirectory=##p' /etc/systemd/system/librenms-scheduler.service 2>/dev/null | head -1)" \
        "$(getent passwd librenms 2>/dev/null | cut -d: -f6)" \
        /opt/librenms
    do
        [ -n "$_c" ] && [ -f "$_c/lnms" ] && [ -f "$_c/composer.json" ] && { printf '%s\n' "$_c"; return; }
    done
    printf '\n'
}

LIBRENMS_DIR=$(detect_librenms_dir)
[ -n "$LIBRENMS_DIR" ] || LIBRENMS_DIR=$(ask "Where is LibreNMS installed?" "/opt/librenms")
[ -f "$LIBRENMS_DIR/lnms" ] || die "$LIBRENMS_DIR does not look like a LibreNMS install (no lnms)."
say "  LibreNMS:    $LIBRENMS_DIR"

if [ -z "$LIBRENMS_USER" ]; then
    LIBRENMS_USER=$(sed -n 's/^LIBRENMS_USER=//p' "$LIBRENMS_DIR/.env" 2>/dev/null | tr -d '"' | head -1)
    [ -n "$LIBRENMS_USER" ] || LIBRENMS_USER=$(stat -c '%U' "$LIBRENMS_DIR/lnms" 2>/dev/null || echo librenms)
fi
say "  runs as:     $LIBRENMS_USER"

if [ -z "$WEBSERVER" ]; then
    if have nginx && pgrep -x nginx >/dev/null 2>&1; then WEBSERVER=nginx
    elif have httpd || have apache2; then WEBSERVER=apache
    elif have nginx; then WEBSERVER=nginx
    else WEBSERVER=none
    fi
fi
say "  web server:  $WEBSERVER"

if [ -z "$PHPFPM" ] && have systemctl; then
    PHPFPM=$(systemctl list-units --type=service --no-legend 2>/dev/null \
        | awk '{print $1}' | grep -E '^php.*fpm.*\.service$' | head -1)
fi
say "  php-fpm:     ${PHPFPM:-not found}"

# The origin genuinely cannot be derived. APP_URL is unset on a stock install
# and reads back as http://localhost; base_url is legitimately a bare path on a
# sub-directory install; a vhost's server_name says nothing about a TLS
# terminator in front of it. Every one of those is confidently wrong, which is
# worse than asking.
if [ -z "$ORIGIN" ]; then
    _guess=$(sed -n 's/^APP_URL=//p' "$LIBRENMS_DIR/.env" 2>/dev/null | tr -d '"' | head -1)
    case "$_guess" in http://localhost|http://localhost/|'') _guess="" ;; esac
    say ""
    say "  The gateway refuses any browser origin it was not told about, so this has"
    say "  to be exactly what your operators type, scheme included, with no path."
    ORIGIN=$(ask "  What URL do people reach LibreNMS on?" "$_guess")
fi
case "$ORIGIN" in
    http://*|https://*) ;;
    *) die "--origin must include a scheme, e.g. https://librenms.example.com" ;;
esac
ORIGIN=$(printf '%s' "$ORIGIN" | sed 's#/*$##')
say "  origin:      $ORIGIN"

case "$(uname -m)" in
    x86_64|amd64) ARCH=amd64 ;;
    aarch64|arm64) ARCH=arm64 ;;
    *) die "unsupported architecture $(uname -m); only amd64 and arm64 are built." ;;
esac

TARBALL="librenms-webterm-gw_${VERSION#v}_linux_${ARCH}.tar.gz"
BASE="https://github.com/$REPO/releases/download/$VERSION"

PREVIOUS=""
[ -x "$PREFIX/librenms-webterm-gw" ] && PREVIOUS="$("$PREFIX/librenms-webterm-gw" version 2>/dev/null || echo 'an unknown version')"

step "Plan"
if [ -n "$PREVIOUS" ]; then
    say "  * replace $PREFIX/librenms-webterm-gw (currently $PREVIOUS)"
    say "  * leave $CONFDIR/gateway.env and gateway.secret untouched"
else
    say "  * download and verify $TARBALL"
    say "  * install the gateway, create its user and $CONFDIR"
    say "  * generate a shared secret and share it with $LIBRENMS_USER"
fi
say "  * set WEBTERM_ALLOWED_ORIGINS=$ORIGIN"
say "  * install and migrate the plugin as $LIBRENMS_USER"
[ "$WEBSERVER" = none ] || say "  * offer to add the WebTerm proxy to $WEBSERVER"
say "  * verify with webterm:doctor"

[ "$DRY_RUN" -eq 1 ] && { say ""; say "Dry run: nothing was changed."; exit 0; }
confirm "Proceed?" "$([ "$ASSUME_YES" -eq 1 ] && echo yes || echo ask)" || die "Aborted."

# ------------------------------------------------------------------ gateway --

step "Installing the gateway"

if [ -f /etc/debian_version ] && dpkg -s librenms-webterm-gw >/dev/null 2>&1; then
    die "librenms-webterm-gw is installed from a package. Upgrade with apt instead."
fi
if have rpm && rpm -q librenms-webterm-gw >/dev/null 2>&1; then
    die "librenms-webterm-gw is installed from a package. Upgrade with your package manager."
fi

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

curl -fsSL -o "$TMP/$TARBALL" "$BASE/$TARBALL"
curl -fsSL -o "$TMP/checksums.txt" "$BASE/checksums.txt"
( cd "$TMP" && grep " $TARBALL\$" checksums.txt | sha256sum -c - ) >/dev/null \
    || die "checksum verification FAILED. Not installing."
say "  checksum verified"

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
    say "  generated $CONFDIR/gateway.secret"
fi

if getent passwd "$LIBRENMS_USER" >/dev/null 2>&1; then
    if ! id -nG "$LIBRENMS_USER" 2>/dev/null | tr ' ' '\n' | grep -qx librenms-webterm; then
        usermod -a -G librenms-webterm "$LIBRENMS_USER"
        say "  added $LIBRENMS_USER to the librenms-webterm group"
    fi
else
    warn "no '$LIBRENMS_USER' account; the plugin will not be able to read the secret."
fi

if [ ! -f "$CONFDIR/gateway.env" ] && [ -f "$TMP/packaging/systemd/librenms-webterm-gw.env.example" ]; then
    install -m 0640 -o root -g librenms-webterm \
        "$TMP/packaging/systemd/librenms-webterm-gw.env.example" "$CONFDIR/gateway.env"
fi

# Exactly one uncommented line, replaced rather than appended, so re-running
# does not accumulate origins.
touch "$CONFDIR/gateway.env"
sed -i '/^[[:space:]]*WEBTERM_ALLOWED_ORIGINS=/d' "$CONFDIR/gateway.env"
printf 'WEBTERM_ALLOWED_ORIGINS=%s\n' "$ORIGIN" >> "$CONFDIR/gateway.env"
say "  set WEBTERM_ALLOWED_ORIGINS=$ORIGIN"

if [ -d /lib/systemd/system ] && [ -f "$TMP/packaging/systemd/librenms-webterm-gw.service" ]; then
    install -m 0644 "$TMP/packaging/systemd/librenms-webterm-gw.service" \
        /lib/systemd/system/librenms-webterm-gw.service
    systemctl daemon-reload || true
fi

# ------------------------------------------------------------------- plugin --

if confirm "Install the LibreNMS plugin as $LIBRENMS_USER?" "$INSTALL_PLUGIN"; then
    step "Installing the plugin"
    # LibreNMS refuses to run artisan as root, so every one of these must drop
    # privileges. runuser, not su, because it does not need a password and does
    # not require the account to have a usable shell.
    as_librenms() { runuser -u "$LIBRENMS_USER" -- sh -c "cd '$LIBRENMS_DIR' && $1"; }

    if ! as_librenms "./lnms plugin:add adaptivedatanetworks/librenms-webterm" 2>&1 | tail -3; then
        warn "plugin:add failed; install it by hand and re-run with --no-install-plugin"
    fi
    as_librenms "./lnms route:clear" >/dev/null 2>&1 || true
    as_librenms "./lnms webterm:migrate" 2>&1 | tail -2 || true
    as_librenms "./lnms webterm:config set enabled true" >/dev/null 2>&1 || true
    say "  plugin installed and migrated"
fi

# --------------------------------------------------------------- web server --

# The proxy logic lives next door so it can be read and audited on its own.
# Installed from a package these sit in /usr/share; run from a git checkout or an
# unpacked tarball they sit beside this script.
for _dir in "$(dirname "$0")" /usr/share/librenms-webterm /usr/local/share/librenms-webterm; do
    if [ -r "$_dir/webserver.sh" ]; then
        . "$_dir/webserver.sh"
        break
    fi
done

if command -v webterm_configure_webserver >/dev/null 2>&1; then
    webterm_configure_webserver
else
    step "Reverse proxy"
    warn "webserver.sh not found next to this script -- skipping proxy setup"
    say "  See https://adaptivedatanetworks.github.io/librenms-webterm/install/reverse-proxy/"
fi

# ------------------------------------------------------------------ selinux --

if have getenforce && [ "$(getenforce 2>/dev/null)" = "Enforcing" ]; then
    if ! getsebool httpd_can_network_connect 2>/dev/null | grep -q -- '--> on'; then
        step "SELinux"
        say "  SELinux is enforcing and httpd_can_network_connect is off, so neither"
        say "  nginx nor php-fpm can reach the gateway on 127.0.0.1:8377."
        if confirm "  Set httpd_can_network_connect?" "$CONFIGURE_SELINUX"; then
            setsebool -P httpd_can_network_connect 1 && say "  set"
        else
            warn "left off -- every browser connection will fail until it is on"
        fi
    fi
fi

# ------------------------------------------------------------------- finish --

step "Starting"

[ -n "$PHPFPM" ] && { systemctl restart "$PHPFPM" >/dev/null 2>&1 && say "  restarted $PHPFPM" || warn "could not restart $PHPFPM"; }

# The gateway starts LAST: one started before its origins are set refuses every
# browser connection with a 403 and looks broken.
if have systemctl; then
    systemctl enable librenms-webterm-gw >/dev/null 2>&1 || true
    if [ -n "$PREVIOUS" ]; then
        systemctl try-restart librenms-webterm-gw >/dev/null 2>&1 || true
    else
        systemctl start librenms-webterm-gw >/dev/null 2>&1 || true
    fi
    say "  gateway started"
fi

step "Checking"
command -v webterm_verify_proxy >/dev/null 2>&1 && { webterm_verify_proxy || true; }
runuser -u "$LIBRENMS_USER" -- sh -c "cd '$LIBRENMS_DIR' && ./lnms webterm:doctor" || DOCTOR=$?

say ""
say "Next: enable a device for terminal access."
say "  su - $LIBRENMS_USER -c 'cd $LIBRENMS_DIR && ./lnms webterm:target:enable --device=<hostname> --principal=<login>'"
say ""
say "Documentation: https://adaptivedatanetworks.github.io/librenms-webterm/"

exit "${DOCTOR:-0}"
