#!/bin/sh
#
# Everything that must pass before a tag.
#
# This exists because two releases were tagged while static analysis was
# failing: the checks were run with `| grep`, which hides the exit code, and the
# output scrolled past unread. A tag is not reversible once Packagist has seen
# it, so the gate has to fail loudly rather than print.
#
set -e

cd "$(dirname "$0")/.."

echo "== composer validate"        && composer validate --strict --no-check-lock
echo "== dependency guard"         && php tools/composer-guard.php
echo "== documentation"            && php tools/check-docs.php
echo "== code style"               && vendor/bin/pint --test
echo "== static analysis"          && vendor/bin/phpstan analyse --no-progress
echo "== tests"                    && vendor/bin/pest

echo "== protocol codegen"
php tools/generate-protocol.php >/dev/null
git diff --exit-code -- src/Protocol.php gateway/internal/proto/proto.go

echo "== gateway"
cd gateway
test -z "$(gofmt -l .)" || { echo "gofmt: files need formatting"; gofmt -l .; exit 1; }
go vet ./...
go test ./...
cd ..

if command -v goreleaser >/dev/null 2>&1; then
    echo "== release build (the workflow is not exercised by CI)"
    goreleaser check
    goreleaser release --snapshot --clean --skip=docker,sign >/dev/null
    tar -tzf dist/librenms-webterm-gw_*_linux_amd64.tar.gz | grep -q 'packaging/install.sh'
    rm -rf dist
else
    echo "!! goreleaser not installed -- the release path is unverified"
fi

echo ""
echo "preflight OK"
