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
# Compares the generated files against a fresh generation, NOT against git.
# `git diff --exit-code` conflates "the generated output is stale" with "you
# have not committed yet", which made preflight unusable in the middle of a
# protocol change -- exactly when it is most worth running.
codegen_before="$(sha256sum src/Protocol.php gateway/internal/proto/proto.go)"
php tools/generate-protocol.php >/dev/null
codegen_after="$(sha256sum src/Protocol.php gateway/internal/proto/proto.go)"
if [ "$codegen_before" != "$codegen_after" ]; then
    echo "protocol codegen was stale; it has been regenerated -- review and commit"
    exit 1
fi

echo "== package lifecycle"
sh tools/package-lifecycle-check.sh
sh tools/skew-claim-check.sh
sh tools/probe-polarity-check.sh
sh tools/dist-contents-check.sh
sh tools/webserver-insert-check.sh
sh -n packaging/install.sh
sh -n packaging/webserver.sh

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
    tar -tzf dist/librenms-webterm-gw_*_linux_amd64.tar.gz | grep -q 'packaging/webserver.sh'
    tar -tzf dist/librenms-webterm-gw_*_linux_amd64.tar.gz | grep -q 'packaging/repository.sh'
    # The release page offers exactly one install.sh, and it must be the bundle.
    # Publishing the module-dependent script instead gives an operator a file
    # that skips the proxy step and still reports success.
    test -s build/install.sh
    grep -q 'webterm_install_from_repository()' build/install.sh
    grep -q 'webterm_configure_webserver()' build/install.sh
    # install.sh sources webserver.sh from /usr/share when it runs as the
    # packaged /usr/sbin/librenms-webterm-setup, so shipping one without the
    # other silently degrades to "skipping proxy setup".
    for _deb in dist/*_linux_amd64.deb; do
        # Listed once into a variable: piping dpkg-deb into `grep -q` three
        # times makes it print "tar subprocess was killed by signal (Broken
        # pipe)" when grep exits early, which puts the word "error" in a log
        # people scan for exactly that word.
        _listing=$(dpkg-deb -c "$_deb")
        for _want in /usr/sbin/librenms-webterm-setup \
                     /usr/share/librenms-webterm/webserver.sh \
                     /usr/share/librenms-webterm/repository.sh \
                     /usr/share/librenms-webterm/lifecycle.sh; do
            printf '%s\n' "$_listing" | grep -q "$_want" || {
                echo "!! the deb is missing $_want" >&2
                exit 1
            }
        done
    done
    if command -v rpm >/dev/null 2>&1; then
        for _rpm in dist/*_linux_amd64.rpm; do
            rpm -qlp "$_rpm" 2>/dev/null | grep -q '/usr/sbin/librenms-webterm-setup'
            rpm -qlp "$_rpm" 2>/dev/null | grep -q '/usr/share/librenms-webterm/webserver.sh'
        done
    fi
    echo "  packages carry the setup script and its helper"
    rm -rf dist
else
    echo "!! goreleaser not installed -- the release path is unverified"
fi

echo ""
echo "preflight OK"
