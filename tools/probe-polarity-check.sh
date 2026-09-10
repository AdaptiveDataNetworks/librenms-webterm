#!/bin/sh
# Pin the one thing about the installer's verification probe that is
# counter-intuitive enough to be re-broken.
#
# On SUCCESS the gateway answers 101 and holds the socket for its handshake
# timeout, so curl exits non-zero (28 under --max-time, 52 without). On every
# FAILURE path -- 403, 404, 502 -- curl exits 0. A probe that checks curl's exit
# code therefore aborts on a healthy install and passes on a broken one, and
# install.sh runs under `set -e`.
#
# So the probe must judge %{http_code} and ignore the exit code entirely. This
# asserts that is still what it does.
set -eu

cd "$(dirname "$0")/.."

# 1. The probe must not be guarded by curl's exit status.
if grep -n 'curl' packaging/webserver.sh | grep -qE '&&|\|\| *(return|die|exit) *1'; then
    echo "FAIL: the probe appears to branch on curl's exit code" >&2
    exit 1
fi

# 2. It must bound the wait, or a healthy gateway stalls the installer 20s.
grep -q -- '--max-time' packaging/webserver.sh || {
    echo "FAIL: the probe has no --max-time; a healthy gateway holds the socket open" >&2
    exit 1
}

# 3. It must treat 101 as the success case.
grep -qE '^\s*101\)' packaging/webserver.sh || {
    echo "FAIL: the probe does not treat 101 as success" >&2
    exit 1
}

# 4. And it must name the failure codes an operator will actually hit.
for code in 403 404; do
    grep -qE "^\s*$code\)|\\b$code\\b" packaging/webserver.sh || {
        echo "FAIL: the probe says nothing about HTTP $code" >&2
        exit 1
    }
done

# 5. curl writes its -w output more than once on an upgraded connection, so the
#    probe must reduce it to a single code. Without this it reported 101000 on a
#    healthy install and 000000 on an unreachable one.
grep -q "cut -c1-3" packaging/webserver.sh || {
    echo "FAIL: the probe uses curl's raw write-out. curl emits it twice on a 101," >&2
    echo "so the status becomes 101000 and no case matches." >&2
    exit 1
}

echo "probe-polarity-check OK"
