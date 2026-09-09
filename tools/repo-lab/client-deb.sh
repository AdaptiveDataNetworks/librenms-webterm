#!/bin/sh
# Debian/Ubuntu client. Asserts the repository works AND that it refuses what
# it must refuse -- a happy-path-only test passes against an unsigned repo.
set -u
fails=0
ok()  { echo "    ok: $1"; }
bad() { echo "    FAIL: $1" >&2; fails=$((fails + 1)); }

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq >/dev/null 2>&1
apt-get install -y -qq --no-install-recommends nginx ca-certificates >/dev/null 2>&1
printf 'server { listen 80 default_server; root /repo/public; autoindex on; }\n' \
    > /etc/nginx/sites-available/default
nginx

BASE=http://127.0.0.1
cp /repo/public/adn-archive-keyring.gpg /usr/share/keyrings/adn.gpg
cp /repo/public/adn-archive-keyring.asc /usr/share/keyrings/adn.asc

source_with() {
    { printf 'Types: deb\nURIs: %s/deb\nSuites: stable\nComponents: main\nArchitectures: amd64\n' "$BASE"
      [ -n "$1" ] && printf 'Signed-By: %s\n' "$1"
    } > /etc/apt/sources.list.d/adn.sources
    # apt reuses a previously valid cached index and exits 0 even when the new
    # fetch fails verification. Without this the negative cases measure nothing.
    rm -rf /var/lib/apt/lists/*
}

echo "  -- positive path --"
source_with /usr/share/keyrings/adn.gpg
upd=$(apt-get update 2>&1); rc=$?
[ "$rc" = 0 ] && ok "apt-get update accepts the signed repository" || bad "apt-get update rc=$rc"
cand=$(apt-cache policy librenms-webterm-gw 2>/dev/null | awk '/Candidate:/{print $2}')
[ -n "$cand" ] && [ "$cand" != "(none)" ] && ok "package is visible (candidate $cand)" \
    || bad "no candidate version -- is the Packages index empty?"
apt-get install -y librenms-webterm-gw >/dev/null 2>&1 \
    && ok "installs from the repository" || bad "install failed"
is_installed() {
    dpkg-query -W -f='${Status}' librenms-webterm-gw 2>/dev/null \
        | grep -q '^install ok installed'
}
is_installed && ok "dpkg agrees it is installed" || bad "not installed"

echo "  -- armored keyring named .asc --"
source_with /usr/share/keyrings/adn.asc
apt-get update >/dev/null 2>&1 && ok "an armored .asc keyring is accepted too" \
                               || bad "armored .asc keyring was rejected"

echo "  -- negative: no Signed-By at all --"
source_with ""
out=$(apt-get update 2>&1); rc=$?
if [ "$rc" != 0 ] && printf '%s' "$out" | grep -qE "NO_PUBKEY|is not signed"; then
    ok "an unverifiable repository is refused (rc=$rc, NO_PUBKEY)"
else
    bad "unverified repo was ACCEPTED (rc=$rc) -- verification is not happening"
fi

echo "  -- negative: package tampered after the index was signed --"
source_with /usr/share/keyrings/adn.gpg
apt-get update -qq >/dev/null 2>&1
# purge, not remove: `apt-get remove` leaves the package in config-files state,
# where `dpkg -s` still exits 0 and a rejected install looks like a success.
apt-get purge -y -qq librenms-webterm-gw >/dev/null 2>&1
is_installed && bad "purge did not remove it -- the tamper case cannot measure anything"
target=$(ls /repo/public/deb/pool/main/l/librenms-webterm-gw/*_amd64.deb | head -1)
size_before=$(stat -c %s "$target")
# Overwrite bytes in place rather than appending. Appending changes the file
# SIZE, and apt rejects on size before it ever computes a hash -- which passes
# this test while proving nothing about hash verification. Same length,
# different content, so only the SHA256 can catch it.
dd if=/dev/zero of="$target" bs=1 seek=$((size_before / 2)) count=64 conv=notrunc status=none
[ "$(stat -c %s "$target")" = "$size_before" ] \
    && ok "tamper preserves file size, so only the hash can detect it" \
    || bad "the tamper changed the size -- this would test the wrong thing"
apt-get clean; rm -rf /var/cache/apt/archives/*.deb /var/cache/apt/archives/partial/*
out=$(apt-get install -y librenms-webterm-gw 2>&1)
# Assert on the message, not the exit status: a stale install would make
# `dpkg -s` report success for a package apt had just refused.
if printf '%s' "$out" | grep -qiE "hash sum mismatch|unexpected size|size mismatch"; then
    ok "a tampered package is rejected ($(printf '%s' "$out" | grep -oiE 'hash sum mismatch|unexpected size' | head -1))"
else
    bad "tampered package was NOT rejected: $(printf '%s' "$out" | tail -2 | tr '\n' ' ')"
fi
is_installed && bad "the tampered package got installed" \
             || ok "and it did not get installed"

[ "$fails" -eq 0 ] || exit 1
