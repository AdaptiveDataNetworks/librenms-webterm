#!/bin/sh
# Build and sign the Adaptive Data Networks package repositories.
#
# Runs on the repository host, as the signing user. Takes whatever is in
# incoming/, folds it into the persistent package store, rebuilds both
# repositories' metadata from that store, signs everything, and publishes the
# result by swapping one symlink.
#
# Nothing here is specific to librenms-webterm. The hostname is generic and
# other packages will land in the same repositories.
set -eu

ROOT="${ADN_REPO_ROOT:-/srv/adn-packages}"
INCOMING="$ROOT/incoming"
STORE="$ROOT/store"
RELEASES="$ROOT/releases"
GNUPGHOME="${ADN_REPO_GNUPGHOME:-$ROOT/gnupg}"
SIGN_UID="${ADN_REPO_SIGN_UID:-packages@adaptivedatanetworks.com}"
ORIGIN_NAME="Adaptive Data Networks"
SUITE="${ADN_REPO_SUITE:-stable}"
COMPONENT=main
ARCHES="amd64 arm64"
KEEP_RELEASES="${ADN_REPO_KEEP:-5}"

export GNUPGHOME

say()  { printf '%s\n' "$*"; }
step() { printf '\n== %s\n' "$*"; }
die()  { printf 'error: %s\n' "$*" >&2; exit 1; }

# gpg must never be able to ask a human anything. On a headless host a prompt is
# a hang; on a workstation it is a dialog box on somebody's desktop. Both have
# happened. The agent conf is the belt, --pinentry-mode loopback the braces.
gpg_batch() {
    gpg --batch --yes --no-tty --pinentry-mode loopback \
        ${ADN_REPO_PASSPHRASE_FILE:+--passphrase-file "$ADN_REPO_PASSPHRASE_FILE"} \
        "$@"
}

[ -d "$GNUPGHOME" ] || die "no GNUPGHOME at $GNUPGHOME -- run adn-repo-setup.sh first."
[ -f "$GNUPGHOME/gpg-agent.conf" ] || printf 'pinentry-program /bin/false\n' > "$GNUPGHOME/gpg-agent.conf"
gpg --list-secret-keys "$SIGN_UID" >/dev/null 2>&1 || die "no secret key for $SIGN_UID in $GNUPGHOME."

for t in apt-ftparchive createrepo_c rpmsign gpg; do
    command -v "$t" >/dev/null 2>&1 || die "$t is not installed."
done

# ---------------------------------------------------------------- ingestion --

step "Ingesting"
mkdir -p "$STORE/rpm" "$STORE/deb/pool/$COMPONENT"
ingested=0

for f in "$INCOMING"/*.deb; do
    [ -e "$f" ] || continue
    base=$(basename "$f")
    # The pool filename is load-bearing. `apt-ftparchive --arch` filters on the
    # FILENAME, not the Architecture control field -- a renamed package is
    # silently absent from the index, and `apt-get update` then succeeds
    # against a repository containing nothing. Never normalise these names.
    case "$base" in
        *_*.deb) ;;
        *) die "refusing '$base': apt-ftparchive --arch needs the _<arch>.deb suffix." ;;
    esac
    name=$(dpkg-deb -f "$f" Package) || die "$base is not a readable .deb"
    letter=$(printf '%s' "$name" | cut -c1)
    dest="$STORE/deb/pool/$COMPONENT/$letter/$name"
    mkdir -p "$dest"
    cp -f "$f" "$dest/$base"
    say "  deb: $base"
    ingested=$((ingested + 1))
done

for f in "$INCOMING"/*.rpm; do
    [ -e "$f" ] || continue
    cp -f "$f" "$STORE/rpm/$(basename "$f")"
    say "  rpm: $(basename "$f")"
    ingested=$((ingested + 1))
done

[ "$ingested" -gt 0 ] || say "  nothing new; rebuilding metadata from the existing store"

# ------------------------------------------------------------- rpm signing --

step "Signing RPMs"
# Two macros, both mandatory, both learned the hard way:
#   %__gpg  -- rpm defaults it to /usr/bin/gpg2, which does not exist on Debian.
#              Without it rpmsign fails, and the UNSIGNED package then reports
#              `digests OK` from `rpm -K` with exit status 0. Silently unsigned.
#   %_gpg_digest_algo -- pin sha256 rather than trusting the default.
cat > "$ROOT/.rpmmacros" <<EOF
%_gpg_name $SIGN_UID
%_gpg_digest_algo sha256
%__gpg $(command -v gpg)
%_gpg_path $GNUPGHOME
EOF

for f in "$STORE"/rpm/*.rpm; do
    [ -e "$f" ] || continue
    # Already signed? rpm -K names the signature only when one is present.
    if rpm --define "_gpg_path $GNUPGHOME" -K "$f" 2>/dev/null | grep -q 'signatures'; then
        continue
    fi
    HOME="$ROOT" rpmsign --addsign "$f" >/dev/null 2>&1 || die "rpmsign failed on $(basename "$f")"
    say "  signed $(basename "$f")"
done

# ------------------------------------------------------------ release tree --

STAMP=$(date -u +%Y%m%dT%H%M%SZ)
NEW="$RELEASES/$STAMP"
step "Building $STAMP"
rm -rf "$NEW"
mkdir -p "$NEW/deb" "$NEW/rpm"

# Hardlinks, so a release tree costs metadata and not a second copy of every
# package. Falls back to a copy across filesystems.
cp -al "$STORE/deb/pool" "$NEW/deb/pool" 2>/dev/null || cp -a "$STORE/deb/pool" "$NEW/deb/pool"
for f in "$STORE"/rpm/*.rpm; do
    [ -e "$f" ] || continue
    ln "$f" "$NEW/rpm/$(basename "$f")" 2>/dev/null || cp "$f" "$NEW/rpm/"
done

# --- apt ---
for a in $ARCHES; do
    d="$NEW/deb/dists/$SUITE/$COMPONENT/binary-$a"
    mkdir -p "$d"
    ( cd "$NEW/deb" && apt-ftparchive --arch "$a" packages pool ) > "$d/Packages"
    gzip -9kf "$d/Packages"
    say "  deb/$a: $(grep -c '^Package:' "$d/Packages") package(s)"
done

cat > "$ROOT/.apt-ftparchive.conf" <<EOF
APT::FTPArchive::Release::Origin "$ORIGIN_NAME";
APT::FTPArchive::Release::Label "$ORIGIN_NAME";
APT::FTPArchive::Release::Suite "$SUITE";
APT::FTPArchive::Release::Codename "$SUITE";
APT::FTPArchive::Release::Architectures "$ARCHES";
APT::FTPArchive::Release::Components "$COMPONENT";
APT::FTPArchive::Release::Description "$ORIGIN_NAME packages";
EOF
( cd "$NEW/deb" && apt-ftparchive -c "$ROOT/.apt-ftparchive.conf" release "dists/$SUITE" ) \
    > "$NEW/deb/dists/$SUITE/Release"

# InRelease (inline signature) is what modern apt fetches. Release.gpg is kept
# for older clients; it costs one signature and removing it breaks them.
gpg_batch --default-key "$SIGN_UID" --clearsign \
    -o "$NEW/deb/dists/$SUITE/InRelease" "$NEW/deb/dists/$SUITE/Release"
gpg_batch --default-key "$SIGN_UID" -abs \
    -o "$NEW/deb/dists/$SUITE/Release.gpg" "$NEW/deb/dists/$SUITE/Release"
say "  deb: Release signed (InRelease + Release.gpg)"

# --- rpm ---
createrepo_c --quiet --update "$NEW/rpm"
gpg_batch --default-key "$SIGN_UID" --detach-sign --armor \
    -o "$NEW/rpm/repodata/repomd.xml.asc" "$NEW/rpm/repodata/repomd.xml"
say "  rpm: repodata built and repomd.xml signed"

# --- the public key, both forms ---
# apt accepts either, but the bytes must match the name: armored content in a
# file named .gpg fails with NO_PUBKEY and "is not signed".
gpg --export --armor "$SIGN_UID" > "$NEW/adn-archive-keyring.asc"
gpg --export        "$SIGN_UID" > "$NEW/adn-archive-keyring.gpg"

# ---------------------------------------------------------------- publish --

step "Publishing"
# Atomic: a client mid-fetch sees either the whole old tree or the whole new
# one, never a Release that disagrees with the Packages beside it.
ln -sfn "$NEW" "$ROOT/public.new"
mv -T "$ROOT/public.new" "$ROOT/public"
say "  public -> releases/$STAMP"

ls -1dt "$RELEASES"/*/ 2>/dev/null | tail -n +$((KEEP_RELEASES + 1)) | while read -r old; do
    rm -rf "$old" && say "  pruned $(basename "$old")"
done

say ""
say "Fingerprint: $(gpg --fingerprint --with-colons "$SIGN_UID" | awk -F: '/^fpr/{print $10; exit}')"
