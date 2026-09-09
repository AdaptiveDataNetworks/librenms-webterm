#!/bin/sh
# The only thing a GitHub Actions deploy key may do on the repository host.
#
# Installed as the forced command in authorized_keys:
#
#   restrict,command="/usr/local/bin/adn-repo-receive" ssh-ed25519 AAAA... actions
#
# WHY THIS TAKES NO PAYLOAD
#
# The obvious design has Actions upload the built packages and the host sign
# whatever arrives. That makes the deploy key a channel for arbitrary content
# into a signing oracle: steal it, push a package, and it comes out signed by
# the Adaptive Data Networks key and trusted by every client.
#
# So the key carries no bytes. It carries one instruction -- "publish this tag"
# -- and the host fetches the artifacts itself from the GitHub release, checks
# them against the published checksums, and (when gh is available) verifies the
# SLSA build provenance that says they came from our release workflow on our
# repository. A stolen key can then only ask the host to publish a release that
# already exists and already verifies. It cannot introduce anything.
set -eu

REPO="${ADN_REPO_SOURCE:-AdaptiveDataNetworks/librenms-webterm}"
ROOT="${ADN_REPO_ROOT:-/srv/adn-packages}"
LOG="$ROOT/publish.log"
LOCK="$ROOT/.publish.lock"

log() { printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*" >> "$LOG"; printf '%s\n' "$*"; }
die() { printf 'error: %s\n' "$*" >&2; log "REFUSED: $*"; exit 1; }

CMD="${SSH_ORIGINAL_COMMAND:-}"
[ -n "$CMD" ] || die "no command. This key may only run: publish vX.Y.Z"

case "$CMD" in
    "publish "*) TAG="${CMD#publish }" ;;
    *) die "unrecognised command: $(printf '%s' "$CMD" | head -c 60)" ;;
esac

# Strict, because everything downstream interpolates this into a URL and a path.
# Anything but a plain semver tag is rejected outright rather than sanitised.
printf '%s' "$TAG" | grep -qE '^v[0-9]{1,4}\.[0-9]{1,4}\.[0-9]{1,4}$' \
    || die "not a release tag: $(printf '%s' "$TAG" | head -c 40)"

log "publish $TAG requested from ${SSH_CLIENT%% *}"

# One publish at a time. Two concurrent runs would race the release symlink.
exec 9>"$LOCK"
flock -n 9 || die "another publish is already running"

WORK=$(mktemp -d "$ROOT/.incoming.XXXXXX")
trap 'rm -rf "$WORK"' EXIT
BASE="https://github.com/$REPO/releases/download/$TAG"

log "fetching checksums"
curl -fsSL --max-time 120 -o "$WORK/checksums.txt" "$BASE/checksums.txt" \
    || die "no checksums.txt for $TAG -- is the release published?"

# Take the artifact list FROM the signed checksums file, not from a guess about
# naming. Anything not listed there cannot be verified and is not fetched.
grep -E '\.(deb|rpm)$' "$WORK/checksums.txt" | while read -r _sum name; do
    case "$name" in
        */*|.*|"") log "skipping suspicious name: $name"; continue ;;
    esac
    curl -fsSL --max-time 300 -o "$WORK/$name" "$BASE/$name" || die "could not fetch $name"
done

( cd "$WORK" && grep -E '\.(deb|rpm)$' checksums.txt | sha256sum -c --quiet - ) \
    || die "CHECKSUM MISMATCH -- refusing to sign anything from $TAG"
log "checksums verified: $(grep -cE '\.(deb|rpm)$' "$WORK/checksums.txt") artifact(s)"

# Provenance is the difference between "these bytes are intact" and "these bytes
# came from our workflow". Optional only because it needs network and a token;
# set ADN_REPO_REQUIRE_ATTESTATION=1 to make a failure fatal.
if command -v gh >/dev/null 2>&1; then
    if gh attestation verify "$WORK/checksums.txt" --repo "$REPO" >/dev/null 2>&1; then
        log "build provenance verified against $REPO"
    elif [ "${ADN_REPO_REQUIRE_ATTESTATION:-0}" = 1 ]; then
        die "provenance verification FAILED for $TAG"
    else
        log "WARNING: provenance could not be verified (continuing)"
    fi
else
    [ "${ADN_REPO_REQUIRE_ATTESTATION:-0}" = 1 ] && die "gh is required for attestation but is not installed"
    log "note: gh not installed, provenance not checked"
fi

mkdir -p "$ROOT/incoming"
rm -f "$ROOT"/incoming/*.deb "$ROOT"/incoming/*.rpm 2>/dev/null || true
for f in "$WORK"/*.deb "$WORK"/*.rpm; do
    [ -e "$f" ] || continue
    mv "$f" "$ROOT/incoming/"
done

log "building"
if "$(dirname "$0")/adn-repo-build.sh" >> "$LOG" 2>&1; then
    log "published $TAG"
else
    die "build failed -- see $LOG"
fi
