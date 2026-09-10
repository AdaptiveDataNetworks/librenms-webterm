#!/bin/sh
# One-time setup for packages.adaptivedatanetworks.com. Idempotent: safe to
# re-run after changing anything below.
set -eu

ROOT="${ADN_REPO_ROOT:-/srv/adn-packages}"
USER_NAME="${ADN_REPO_USER:-adnpkg}"
SIGN_UID="${ADN_REPO_SIGN_UID:-packages@adaptivedatanetworks.com}"
SIGN_NAME="${ADN_REPO_SIGN_NAME:-Adaptive Data Networks Package Signing}"
HOSTNAME_FQDN="${ADN_REPO_HOST:-packages.adaptivedatanetworks.com}"
BINDIR=/usr/local/bin

say()  { printf '%s\n' "$*"; }
step() { printf '\n== %s\n' "$*"; }
die()  { printf 'error: %s\n' "$*" >&2; exit 1; }
have() { command -v "$1" >/dev/null 2>&1; }

[ "$(id -u)" -eq 0 ] || die "run this as root."

step "Packages"
# dnf FIRST, deliberately. This script installs EPEL's dpkg and apt-utils on an
# EL host so it can build the deb repository -- and EPEL's apt package puts
# apt-get on the PATH. Testing for apt-get first therefore makes the script take
# the Debian branch on its own second run, on a Rocky box, and fail with
# "E: Unable to locate package nginx". Debian never has dnf, so this order is
# unambiguous in both directions.
if have dnf; then
    # The deb half needs Debian tooling even on an EL host. apt-ftparchive comes
    # from EPEL's `apt` package and dpkg-deb from `dpkg`; without both, the deb
    # repository silently ends up with an empty Packages index rather than
    # failing loudly.
    dnf install -y -q epel-release >/dev/null 2>&1 || true
    # CRB (PowerTools on EL8) is disabled by default and is the only place
    # zlib-ng lives. EPEL's dpkg links against libz-ng.so.2, so without CRB the
    # whole Debian toolchain is uninstallable and the deb half of the repository
    # cannot be built at all. The error names libz-ng, not CRB, which is why
    # this is worth doing rather than leaving to the operator.
    dnf install -y -q dnf-plugins-core >/dev/null 2>&1 || true
    for _crb in crb powertools codeready-builder-for-rhel-9-x86_64-rpms; do
        dnf config-manager --set-enabled "$_crb" >/dev/null 2>&1 && break
    done
    # curl is deliberately absent from this list: EL9 ships curl-minimal, which
    # already provides /usr/bin/curl and CONFLICTS with the full curl package,
    # so naming it here fails the whole transaction.
    dnf install -y -q nginx gnupg2 createrepo_c rpm-sign apt-utils dpkg dpkg-dev >/dev/null 2>&1 \
        || dnf install -y -q nginx gnupg2 createrepo_c rpm-sign >/dev/null
    have curl || dnf install -y -q --allowerasing curl >/dev/null 2>&1 || true
    for t in apt-ftparchive dpkg-deb; do
        have "$t" || die "$t is missing and is required to build the deb repository.
  On EL it comes from EPEL: dnf install epel-release && dnf install apt-utils dpkg"
    done
elif have apt-get; then
    DEBIAN_FRONTEND=noninteractive apt-get update -qq
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        nginx gnupg apt-utils createrepo-c rpm curl ca-certificates >/dev/null
else
    die "unsupported distribution: need apt-get or dnf."
fi
say "  installed"

step "User and layout"
# /bin/sh, NOT nologin. sshd runs a forced command through the account's login
# shell, so a nologin shell makes every publish fail with "This account is
# currently not available" -- and the restriction buys nothing here anyway,
# because what constrains this account is the forced command plus `restrict` in
# authorized_keys, not the shell.
getent passwd "$USER_NAME" >/dev/null 2>&1 || useradd --system --home-dir "$ROOT" \
    --shell /bin/sh --comment "ADN package repository" "$USER_NAME"
# Correct it on a re-run over an account created by an earlier version.
[ "$(getent passwd "$USER_NAME" | cut -d: -f7)" = /bin/sh ] || \
    usermod --shell /bin/sh "$USER_NAME"
install -d -o "$USER_NAME" -g "$USER_NAME" -m 0755 "$ROOT" "$ROOT/releases" "$ROOT/store"
install -d -o "$USER_NAME" -g "$USER_NAME" -m 0750 "$ROOT/incoming"
# The signing key lives here and nothing else may read it.
install -d -o "$USER_NAME" -g "$USER_NAME" -m 0700 "$ROOT/gnupg"
# sshd refuses an authorized_keys file whose directory chain is group- or
# world-writable, and does it quietly -- the login just fails as if the key
# were wrong.
install -d -o "$USER_NAME" -g "$USER_NAME" -m 0700 "$ROOT/.ssh"
touch "$ROOT/.ssh/authorized_keys"
chown "$USER_NAME:$USER_NAME" "$ROOT/.ssh/authorized_keys"
chmod 0600 "$ROOT/.ssh/authorized_keys"
touch "$ROOT/publish.log"; chown "$USER_NAME:$USER_NAME" "$ROOT/publish.log"
say "  $ROOT laid out, owned by $USER_NAME"

step "Scripts"
for s in adn-repo-build.sh adn-repo-receive.sh; do
    install -m 0755 "$(dirname "$0")/$s" "$BINDIR/${s%.sh}"
    say "  $BINDIR/${s%.sh}"
done

step "Signing key"
if runuser -u "$USER_NAME" -- env GNUPGHOME="$ROOT/gnupg" gpg --list-secret-keys "$SIGN_UID" >/dev/null 2>&1; then
    say "  already present, leaving it alone"
else
    printf 'pinentry-program /bin/false\n' > "$ROOT/gnupg/gpg-agent.conf"
    chown "$USER_NAME:$USER_NAME" "$ROOT/gnupg/gpg-agent.conf"
    # RSA 4096. NOT ed25519: EL8's rpm 4.14 cannot import an ed25519 public key
    # and reports `digests SIGNATURES NOT OK` for anything signed with one, so
    # choosing it would silently break every EL8 client with a message that
    # looks like tampering. Verified against rocky:8 and rocky:9.
    #
    # No passphrase, deliberately. This key is used by an unattended service; a
    # passphrase would have to sit in a file beside it, readable by the same
    # user, protecting nothing. What protects it is 0700 ownership by a
    # nologin system account and the host itself. If you would rather have one,
    # set a passphrase and point ADN_REPO_PASSPHRASE_FILE at it.
    #
    # Five years. An EXPIRED signing key breaks apt and dnf on every installed
    # client at once, so put the renewal in a calendar now -- see the rotation
    # section in the docs.
    cat > "$ROOT/gnupg/keyparams" <<EOF
%echo Generating the Adaptive Data Networks package signing key
Key-Type: RSA
Key-Length: 4096
Key-Usage: sign
Name-Real: $SIGN_NAME
Name-Email: $SIGN_UID
Expire-Date: 5y
%no-protection
%commit
EOF
    chown "$USER_NAME:$USER_NAME" "$ROOT/gnupg/keyparams"
    runuser -u "$USER_NAME" -- env GNUPGHOME="$ROOT/gnupg" \
        gpg --batch --pinentry-mode loopback --generate-key "$ROOT/gnupg/keyparams" >/dev/null 2>&1 \
        || die "key generation failed"
    rm -f "$ROOT/gnupg/keyparams"
    say "  generated RSA 4096, expires in 5 years"
fi
FPR=$(runuser -u "$USER_NAME" -- env GNUPGHOME="$ROOT/gnupg" \
      gpg --fingerprint --with-colons "$SIGN_UID" | awk -F: '/^fpr/{print $10; exit}')
say "  fingerprint: $FPR"

step "nginx"
CONF=/etc/nginx/conf.d/adn-packages.conf
[ -d /etc/nginx/conf.d ] || CONF=/etc/nginx/sites-enabled/adn-packages
sed "s/@HOSTNAME@/$HOSTNAME_FQDN/g; s#@ROOT@#$ROOT#g" \
    "$(dirname "$0")/nginx-packages.conf" > "$CONF"
if nginx -t >/dev/null 2>&1; then
    systemctl reload nginx >/dev/null 2>&1 || systemctl start nginx >/dev/null 2>&1 || true
    say "  $CONF installed and nginx reloaded"
else
    rm -f "$CONF"
    say "  nginx rejected the config; nothing installed. Run 'nginx -t' to see why."
fi

step "SELinux"
if have getenforce && [ "$(getenforce 2>/dev/null)" != Disabled ]; then
    # nginx runs as httpd_t and may only read httpd_sys_content_t. Nothing under
    # /srv carries that label by default, so on an enforcing host every request
    # is denied and the whole repository serves 403 -- with the denial only in
    # the audit log, not in nginx's error log, which is what makes it puzzling.
    if ! have semanage; then
        if have dnf; then
            dnf install -y -q policycoreutils-python-utils >/dev/null 2>&1 || true
        elif have apt-get; then
            DEBIAN_FRONTEND=noninteractive apt-get install -y -qq policycoreutils-python-utils >/dev/null 2>&1 || true
        fi
    fi
    if have semanage; then
        # Label ONLY what nginx serves. Labelling the whole tree
        # httpd_sys_content_t looks tidier and breaks ssh: sshd_t cannot SEARCH
        # a httpd_sys_content_t directory, so it can no longer traverse $ROOT to
        # reach $ROOT/.ssh, and every publish fails with
        #   Could not open user 'adnpkg' authorized keys ...: Permission denied
        # while the client sees only "Permission denied (publickey)". $ROOT
        # itself therefore keeps its default type, which both domains can search.
        # store/ carries the same label as releases/ because the release trees
        # are HARDLINKED from it, and a hardlink shares its inode and therefore
        # its SELinux label. Label only releases/ and the metadata serves fine
        # while every single .deb and .rpm returns 403, because the package
        # files really are the store's inodes wearing the store's label.
        for _served in releases store; do
            semanage fcontext -a -t httpd_sys_content_t "$ROOT/$_served(/.*)?" 2>/dev/null \
                || semanage fcontext -m -t httpd_sys_content_t "$ROOT/$_served(/.*)?" 2>/dev/null || true
        done
        # And drop the over-broad rule if an earlier version of this script set it.
        semanage fcontext -d "$ROOT(/.*)?" 2>/dev/null || true
        semanage fcontext -a -t ssh_home_t "$ROOT/\.ssh(/.*)?" 2>/dev/null \
            || semanage fcontext -m -t ssh_home_t "$ROOT/\.ssh(/.*)?" 2>/dev/null || true
        semanage fcontext -a -t gpg_secret_t "$ROOT/gnupg(/.*)?" 2>/dev/null \
            || semanage fcontext -m -t gpg_secret_t "$ROOT/gnupg(/.*)?" 2>/dev/null || true
        have restorecon && restorecon -RF "$ROOT" >/dev/null 2>&1 || true
        say "  releases/ and store/ are httpd_sys_content_t; .ssh is ssh_home_t; gnupg is gpg_secret_t"
    else
        say "  WARNING: semanage is unavailable and SELinux is $(getenforce)."
        say "  nginx will be denied read access and serve 403 for everything."
        say "  Install policycoreutils-python-utils and re-run."
    fi
else
    say "  not enforcing, nothing to do"
fi

step "Next"
say ""
say "1. Point $HOSTNAME_FQDN at this host, then get a certificate:"
say "     certbot --nginx -d $HOSTNAME_FQDN"
say ""
say "2. Authorise the GitHub Actions deploy key. Add ONE line to"
say "   $ROOT/.ssh/authorized_keys, with the public half of the key you put in"
say "   the PACKAGES_SSH_KEY secret:"
say ""
say "     restrict,command=\"$BINDIR/adn-repo-receive\" ssh-ed25519 AAAA... github-actions"
say ""
say "   'restrict' disables port/agent/X11 forwarding, pty allocation and user rc"
say "   files. The forced command ignores whatever the client asks for and accepts"
say "   only 'publish vX.Y.Z'."
say ""
say "3. Publish the key fingerprint in your docs so operators can check it:"
say "     $FPR"
