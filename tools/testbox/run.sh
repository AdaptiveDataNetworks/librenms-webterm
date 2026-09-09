#!/usr/bin/env bash
# Exercise packaging/install.sh end to end on a real distro.
#
#   tools/testbox/run.sh                       # every distro, both web servers
#   tools/testbox/run.sh rocky nginx           # one combination
#   KEEP=1 tools/testbox/run.sh debian apache  # leave the container up to poke at
#
# Needs podman (rootless is fine) and goreleaser. Images are cached, so the
# first run is slow and later ones are not.
set -euo pipefail
cd "$(dirname "$0")/../.."

declare -A BASES=(
    [rocky]=docker.io/rockylinux/rockylinux:9
    [alma]=docker.io/library/almalinux:9
    [debian]=docker.io/library/debian:12
    [ubuntu]=docker.io/library/ubuntu:24.04
)
declare -A FAMILY=([rocky]=rhel [alma]=rhel [debian]=debian [ubuntu]=debian)

DISTROS=(${1:-rocky alma debian ubuntu})
SERVERS=(${2:-nginx apache})
FAILED=()

command -v podman >/dev/null || { echo "podman is required" >&2; exit 1; }

# Always rebuild: the package is what is under test, and a stale dist/ silently
# tests the previous commit. (It cost one confusing run to learn that.)
if [[ -z ${REUSE_DIST:-} ]]; then
    echo "== building packages"
    goreleaser release --snapshot --clean --skip=docker,sign >/dev/null
fi
DEB=$(ls dist/*_linux_amd64.deb | head -1)
RPM=$(ls dist/*_linux_amd64.rpm | head -1)

for distro in "${DISTROS[@]}"; do
    img="librenms-webterm-testbox:$distro"
    if ! podman image exists "$img"; then
        echo "== building $img"
        podman build -q --build-arg "BASE=${BASES[$distro]}" \
            --build-arg "FAMILY=${FAMILY[$distro]}" \
            -t "$img" -f tools/testbox/Containerfile tools/testbox >/dev/null
    fi

    for ws in "${SERVERS[@]}"; do
        echo ""
        echo "=============== $distro / $ws ==============="
        c=$(podman run -d --systemd=always \
            -v "$PWD:/src:ro" \
            "$img")
        trap 'podman rm -f "$c" >/dev/null 2>&1 || true' EXIT

        for _ in $(seq 1 30); do
            st=$(podman exec "$c" systemctl is-system-running 2>/dev/null || true)
            [[ $st == running || $st == degraded ]] && break
        done

        if podman exec "$c" env WS="$ws" DEB="/src/$DEB" RPM="/src/$RPM" \
                bash /src/tools/testbox/inside.sh; then
            echo "  PASS: $distro / $ws"
        else
            echo "  FAIL: $distro / $ws"
            FAILED+=("$distro/$ws")
        fi

        if [[ -n ${KEEP:-} ]]; then
            echo "  container left running: podman exec -it $c bash"
            trap - EXIT
        else
            podman rm -f "$c" >/dev/null 2>&1 || true
            trap - EXIT
        fi
    done
done

echo ""
if ((${#FAILED[@]})); then
    printf 'FAILED: %s\n' "${FAILED[*]}" >&2
    exit 1
fi
echo "testbox: all combinations passed"
