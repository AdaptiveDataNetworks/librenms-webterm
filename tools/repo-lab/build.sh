#!/bin/sh
# Runs inside a Debian container. Generates a THROWAWAY signing key and drives
# the real packaging/repo/adn-repo-build.sh, so the test exercises the shipped
# script rather than a reimplementation of it.
set -eu
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq >/dev/null 2>&1
apt-get install -y -qq --no-install-recommends \
    apt-utils createrepo-c rpm gnupg dpkg-dev >/dev/null 2>&1

export ADN_REPO_ROOT=/repo
export GNUPGHOME=/repo/gnupg
mkdir -p "$GNUPGHOME" /repo/incoming; chmod 700 "$GNUPGHOME"
printf 'pinentry-program /bin/false\n' > "$GNUPGHOME/gpg-agent.conf"

# RSA 4096, because EL8's rpm 4.14 cannot import an ed25519 public key at all
# and reports `SIGNATURES NOT OK` for anything signed with one.
cat > /tmp/keyparams <<'EOF'
%echo generating throwaway test key
Key-Type: RSA
Key-Length: 4096
Key-Usage: sign
Name-Real: ADN Repo Lab
Name-Email: packages@adaptivedatanetworks.com
Expire-Date: 0
%no-protection
%commit
EOF
gpg --batch --pinentry-mode loopback --generate-key /tmp/keyparams >/dev/null 2>&1

cp /artifacts/*.deb /artifacts/*.rpm /repo/incoming/
sh /src/packaging/repo/adn-repo-build.sh 2>&1 | sed 's/^/    /'

# Leave the tree readable by the client containers.
chmod -R a+rX /repo/releases /repo/public/ 2>/dev/null || true
gpgconf --homedir "$GNUPGHOME" --kill gpg-agent 2>/dev/null || true
