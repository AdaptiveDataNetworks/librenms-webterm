#!/bin/sh
# Exercise the publish trigger the way GitHub Actions will: over ssh, through
# the forced command, against a release served locally.
#
# This is the piece most likely to fail first on a real host, because nothing
# about it is visible until someone tags a release.
set -u
fails=0
ok()  { echo "    ok: $1"; }
bad() { echo "    FAIL: $1" >&2; fails=$((fails + 1)); }

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq >/dev/null 2>&1
apt-get install -y -qq --no-install-recommends \
    openssh-server openssh-client nginx gnupg apt-utils createrepo-c rpm curl \
    ca-certificates dpkg-dev >/dev/null 2>&1

cp -r /src/packaging /tmp/packaging
sh /tmp/packaging/repo/adn-repo-setup.sh >/tmp/setup.log 2>&1 \
    && ok "adn-repo-setup.sh completed" \
    || { bad "setup failed: $(tail -3 /tmp/setup.log | tr '\n' ' ')"; exit 1; }

# --- stand up a fake GitHub release on localhost ---------------------------
REL=/srv/fakerelease/v9.9.9
mkdir -p "$REL"
cp /artifacts/*.deb /artifacts/*.rpm "$REL/"
( cd "$REL" && sha256sum ./*.deb ./*.rpm | sed 's# \./# #' > checksums.txt )
printf 'server { listen 8099; root /srv/fakerelease; autoindex on; }\n' \
    > /etc/nginx/conf.d/fakerelease.conf
nginx -s reload 2>/dev/null || nginx 2>/dev/null || true
sleep 1
printf 'ADN_REPO_BASE_URL=http://127.0.0.1:8099\n' > /etc/adn-repo.conf

# --- sshd + the deploy key -------------------------------------------------
ssh-keygen -t ed25519 -N '' -f /root/deploykey -C github-actions >/dev/null 2>&1
printf 'restrict,command="/usr/local/bin/adn-repo-receive" %s\n' "$(cat /root/deploykey.pub)" \
    > /srv/adn-packages/.ssh/authorized_keys
chown adnpkg:adnpkg /srv/adn-packages/.ssh/authorized_keys
chmod 0600 /srv/adn-packages/.ssh/authorized_keys
mkdir -p /run/sshd
ssh-keygen -A >/dev/null 2>&1
/usr/sbin/sshd >/dev/null 2>&1
sleep 1
pgrep -x sshd >/dev/null && ok "sshd is running" || bad "sshd did not start"

as_actions() {
    ssh -i /root/deploykey -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
        -o BatchMode=yes -o LogLevel=ERROR adnpkg@127.0.0.1 "$@" 2>&1
}

echo "  -- the account can actually run a forced command --"
out=$(as_actions "publish v9.9.9"); rc=$?
printf '%s' "$out" | grep -qi "account is currently not available" \
    && bad "the account's shell blocks forced commands (nologin?)" \
    || ok "the login shell permits the forced command"

echo "  -- a real publish --"
if [ "$rc" = 0 ]; then
    ok "publish v9.9.9 succeeded"
else
    bad "publish failed: $(printf '%s' "$out" | tail -3 | tr '\n' ' ')"
fi
[ -L /srv/adn-packages/public ] && ok "public symlink was created" || bad "no public symlink"
grep -q '^Package: librenms-webterm-gw' \
    /srv/adn-packages/public/deb/dists/stable/main/binary-amd64/Packages 2>/dev/null \
    && ok "the deb index lists the package" || bad "deb index is empty"
[ -f /srv/adn-packages/public/rpm/repodata/repomd.xml.asc ] \
    && ok "repomd.xml was signed" || bad "repomd.xml.asc missing"
# Import the published key FIRST. Without it a correctly signed package prints
# "digests SIGNATURES NOT OK" -- caps, and meaning "I cannot check this", not
# "this is unsigned". An unsigned package prints "digests OK" and exits 0. So
# the assertion is: key imported, then the lowercase word "signatures".
rpm --import /srv/adn-packages/public/adn-archive-keyring.asc 2>/dev/null
kout=$(rpm -K /srv/adn-packages/public/rpm/*.rpm 2>&1)
printf '%s' "$kout" | grep -q 'signatures OK' \
    && ok "the published RPM is signed and verifies against the published key" \
    || bad "the published RPM did not verify: $kout"

echo "  -- the key must refuse anything else --"
for evil in "id" "rm -rf /tmp/x" "publish; id" "publish ../../../etc" \
            "publish v1.0.0/../../x" "publish latest" "publish v1.0.0 && id"; do
    out=$(as_actions "$evil")
    if printf '%s' "$out" | grep -qE "unrecognised command|not a release tag"; then
        ok "refused: $evil"
    else
        bad "ACCEPTED '$evil' -> $(printf '%s' "$out" | head -1)"
    fi
done

out=$(as_actions)
printf '%s' "$out" | grep -q "may only run" \
    && ok "refused: an interactive shell with no command" \
    || bad "a bare login was not refused: $(printf '%s' "$out" | head -1)"

echo "  -- a tag that does not exist --"
out=$(as_actions "publish v0.0.1")
printf '%s' "$out" | grep -qi "no checksums.txt\|could not fetch" \
    && ok "a nonexistent release is reported, not silently published" \
    || bad "unexpected: $(printf '%s' "$out" | head -1)"

[ "$fails" -eq 0 ] || exit 1
