#!/bin/sh
# EL client. The signature assertions here deliberately grep the OUTPUT: an
# unsigned RPM makes `rpm -K` print "digests OK" and exit 0.
set -u
fails=0
ok()  { echo "    ok: $1"; }
bad() { echo "    FAIL: $1" >&2; fails=$((fails + 1)); }

dnf install -y -q nginx >/dev/null 2>&1
printf 'server { listen 80 default_server; root /repo/public; autoindex on; }\n' \
    > /etc/nginx/conf.d/repo.conf
rm -f /etc/nginx/nginx.conf.default; nginx 2>/dev/null || nginx -s reload 2>/dev/null || true
sleep 1

cat > /etc/yum.repos.d/adn.repo <<'EOF'
[adn]
name=Adaptive Data Networks
baseurl=http://127.0.0.1/rpm/
enabled=1
gpgcheck=1
repo_gpgcheck=1
gpgkey=http://127.0.0.1/adn-archive-keyring.asc
metadata_expire=300
EOF

echo "  -- positive path --"
rpm --import http://127.0.0.1/adn-archive-keyring.asc 2>/dev/null \
    && ok "the published key imports" || bad "key import failed"

pkg=$(ls /repo/public/rpm/*.rpm | head -1)
kout=$(rpm -K "$pkg" 2>&1)
# The word "signatures" is the whole assertion. Its absence means unsigned, and
# `rpm -K` still exits 0 in that case.
printf '%s' "$kout" | grep -q 'signatures' \
    && ok "the published RPM carries a signature ($kout)" \
    || bad "RPM IS NOT SIGNED -- rpm -K said: $kout"

dnf clean all -q >/dev/null 2>&1
out=$(dnf install -y librenms-webterm-gw 2>&1); rc=$?
[ "$rc" = 0 ] && ok "installs with gpgcheck=1 and repo_gpgcheck=1" \
              || bad "install failed: $(printf '%s' "$out" | tail -2 | tr '\n' ' ')"
rpm -q librenms-webterm-gw >/dev/null 2>&1 && ok "rpm agrees it is installed" || bad "not installed"

echo "  -- negative: forget the key entirely --"
dnf remove -y -q librenms-webterm-gw >/dev/null 2>&1
rpm -e --allmatches gpg-pubkey --nodeps 2>/dev/null || true
sed -i 's|^gpgkey=.*|gpgkey=http://127.0.0.1/nonexistent.asc|' /etc/yum.repos.d/adn.repo
dnf clean all -q >/dev/null 2>&1
out=$(dnf install -y librenms-webterm-gw 2>&1); rc=$?
if [ "$rc" != 0 ]; then
    ok "without the key, dnf refuses to install (rc=$rc)"
else
    bad "dnf INSTALLED without a usable key -- gpgcheck is not doing anything"
fi
rpm -q librenms-webterm-gw >/dev/null 2>&1 && bad "it got installed anyway" || ok "and nothing was installed"

echo "  -- negative: repomd signature removed --"
sed -i 's|^gpgkey=.*|gpgkey=http://127.0.0.1/adn-archive-keyring.asc|' /etc/yum.repos.d/adn.repo
rpm --import http://127.0.0.1/adn-archive-keyring.asc 2>/dev/null || true
mv /repo/public/rpm/repodata/repomd.xml.asc /tmp/repomd.xml.asc
dnf clean all -q >/dev/null 2>&1
out=$(dnf install -y librenms-webterm-gw 2>&1); rc=$?
[ "$rc" != 0 ] && ok "repo_gpgcheck rejects unsigned repodata (rc=$rc)" \
              || bad "unsigned repodata was ACCEPTED"
mv /tmp/repomd.xml.asc /repo/public/rpm/repodata/repomd.xml.asc

[ "$fails" -eq 0 ] || exit 1
