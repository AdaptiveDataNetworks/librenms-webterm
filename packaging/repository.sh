# Install the gateway from packages.adaptivedatanetworks.com.
#
# This is the default path on any host with apt or dnf, and it exists so that
# "one command installs WebTerm" and "apt upgrade keeps it current" are the same
# story. A tarball install is a fine thing but it is a dead end: nothing ever
# tells the operator a new gateway exists, and nothing upgrades it.
#
# Sourced by install.sh. The URLs and the fingerprint here are the same ones
# docs/install/package-repo.md publishes; if they diverge, the docs are wrong.

WEBTERM_REPO_HOST="${WEBTERM_REPO_HOST:-packages.adaptivedatanetworks.com}"
WEBTERM_REPO_KEY_URL="https://$WEBTERM_REPO_HOST/adn-archive-keyring.asc"
WEBTERM_REPO_FPR="1880C40E19DC890F8F008D0CF7297BB14290D3DF"

webterm_repo_available() {
    have curl || return 1
    curl -fsSL --max-time 15 -o /dev/null "$WEBTERM_REPO_KEY_URL" 2>/dev/null
}

# Fetch the signing key and refuse it unless the fingerprint is the published
# one. Without this the "add our repository" step is trust-on-first-use against
# whatever answers that hostname.
webterm_repo_fetch_key() {
    _tmpkey="$1"
    curl -fsSL --max-time 30 -o "$_tmpkey" "$WEBTERM_REPO_KEY_URL" || return 1
    have gpg || return 0
    _got=$(gpg --show-keys --with-colons "$_tmpkey" 2>/dev/null | awk -F: '/^fpr/{print $10; exit}')
    if [ "$_got" != "$WEBTERM_REPO_FPR" ]; then
        warn "the signing key served by $WEBTERM_REPO_HOST is NOT the published one."
        say  "    expected $WEBTERM_REPO_FPR"
        say  "    got      ${_got:-<unreadable>}"
        say  "  Refusing to add the repository. Report this before installing anything."
        return 1
    fi
    return 0
}

webterm_install_from_repository() {
    _key=$(mktemp)
    webterm_repo_fetch_key "$_key" || { rm -f "$_key"; return 1; }

    if have apt-get; then
        install -d -m 0755 /usr/share/keyrings
        if have gpg; then
            gpg --dearmor < "$_key" > /usr/share/keyrings/adn-archive-keyring.gpg
            _signed_by=/usr/share/keyrings/adn-archive-keyring.gpg
        else
            # apt takes an armored key too, as long as the FILENAME says so --
            # armored bytes in a .gpg file fail with NO_PUBKEY, which reads like
            # a signing problem and is a file-format one.
            cp "$_key" /usr/share/keyrings/adn-archive-keyring.asc
            _signed_by=/usr/share/keyrings/adn-archive-keyring.asc
        fi
        cat > /etc/apt/sources.list.d/adn.sources <<EOF
Types: deb
URIs: https://$WEBTERM_REPO_HOST/deb
Suites: stable
Components: main
Architectures: amd64 arm64
Signed-By: $_signed_by
EOF
        say "  added /etc/apt/sources.list.d/adn.sources"
        DEBIAN_FRONTEND=noninteractive apt-get update -qq >/dev/null 2>&1
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq librenms-webterm-gw >/dev/null 2>&1 \
            || { rm -f "$_key"; return 1; }
    elif have dnf || have yum; then
        rpm --import "$_key" 2>/dev/null || true
        cat > /etc/yum.repos.d/adn.repo <<EOF
[adn]
name=Adaptive Data Networks
baseurl=https://$WEBTERM_REPO_HOST/rpm/
enabled=1
gpgcheck=1
repo_gpgcheck=1
gpgkey=$WEBTERM_REPO_KEY_URL
metadata_expire=300
EOF
        say "  added /etc/yum.repos.d/adn.repo"
        _pkg=$(command -v dnf >/dev/null 2>&1 && echo dnf || echo yum)
        "$_pkg" install -y -q librenms-webterm-gw >/dev/null 2>&1 || { rm -f "$_key"; return 1; }
    else
        rm -f "$_key"
        return 1
    fi

    rm -f "$_key"
    say "  installed librenms-webterm-gw from $WEBTERM_REPO_HOST"
    say "  future releases arrive with your normal package updates"
    return 0
}
