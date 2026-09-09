#!/usr/bin/env bash
# Prove the package repositories work, and that they refuse what they must.
#
#   tools/repo-check.sh
#
# Builds a repository from the packages GoReleaser just produced, using a
# throwaway RSA key and the REAL packaging/repo/adn-repo-build.sh, then points
# real apt and real dnf at it over HTTP and tries to break it.
#
# The negative cases carry the weight. A test that only installs successfully
# passes just as happily against a repository with gpgcheck=0, which is exactly
# the failure nobody notices.
#
# Needs podman (rootless is fine) and goreleaser.
set -euo pipefail
cd "$(dirname "$0")/.."

command -v podman >/dev/null || { echo "podman is required" >&2; exit 1; }

WORK=$(mktemp -d)
trap 'podman unshare rm -rf "$WORK" 2>/dev/null || rm -rf "$WORK" 2>/dev/null || true' EXIT

if [[ -z ${REUSE_DIST:-} ]]; then
    echo "== building packages"
    goreleaser release --snapshot --clean --skip=docker,sign >/dev/null
fi
mkdir -p "$WORK/artifacts" "$WORK/repo"
cp dist/*_linux_amd64.deb dist/*_linux_amd64.rpm "$WORK/artifacts/"

echo "== building and signing the repository (the shipped script)"
podman run --rm \
    -v "$PWD:/src:ro" -v "$WORK/artifacts:/artifacts:ro" -v "$WORK/repo:/repo" \
    docker.io/library/debian:12 sh /src/tools/repo-lab/build.sh

fails=0
for client in deb rpm; do
    case $client in
        deb) img=docker.io/library/debian:12 ;;
        rpm) img=docker.io/rockylinux/rockylinux:9 ;;
    esac
    echo ""
    echo "=============== $client client ($img) ==============="
    if podman run --rm -v "$PWD:/src:ro" -v "$WORK/repo:/repo" "$img" \
            sh "/src/tools/repo-lab/client-$client.sh"; then
        echo "  PASS: $client"
    else
        echo "  FAIL: $client"
        fails=$((fails + 1))
    fi
done

echo ""
[[ $fails -eq 0 ]] || { echo "repo-check: $fails client(s) failed" >&2; exit 1; }
echo "repo-check OK"
