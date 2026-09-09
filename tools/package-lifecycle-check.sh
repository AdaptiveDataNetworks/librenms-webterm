#!/bin/sh
# Exercise the deb and rpm maintainer-script argument conventions.
#
# These cannot be unit-tested through the package managers here, but the branch
# logic is the part that was wrong, and it is pure shell. Every case below is a
# real invocation dpkg or rpm makes.
set -eu

. "$(dirname "$0")/../packaging/scripts/lifecycle.sh"

fail=0
check() {
    # $1 description, $2 expected (yes|no), $3.. the call
    _desc=$1; _want=$2; shift 2
    if "$@"; then _got=yes; else _got=no; fi
    if [ "$_got" = "$_want" ]; then
        printf '  ok    %s\n' "$_desc"
    else
        printf '  FAIL  %s (wanted %s, got %s)\n' "$_desc" "$_want" "$_got"
        fail=1
    fi
}

echo "== is this an upgrade install? =="
check "rpm: fresh install (\$1=1)"          no  webterm_is_upgrade_install 1
check "rpm: upgrade (\$1=2)"                yes webterm_is_upgrade_install 2
check "rpm: upgrade from many (\$1=3)"      yes webterm_is_upgrade_install 3
check "deb: fresh configure (no \$2)"       no  webterm_is_upgrade_install configure
check "deb: configure with old version"     yes webterm_is_upgrade_install configure 1.0.8
check "no arguments at all"                 no  webterm_is_upgrade_install

echo "== is this a final removal? =="
check "rpm: last copy going (\$1=0)"        yes webterm_is_final_removal 0
check "rpm: upgrade (\$1=1)"                no  webterm_is_final_removal 1
check "deb: remove"                         yes webterm_is_final_removal remove
check "deb: purge"                          yes webterm_is_final_removal purge
check "deb: upgrade"                        no  webterm_is_final_removal upgrade
check "deb: deconfigure"                    no  webterm_is_final_removal deconfigure
check "deb: failed-upgrade"                 no  webterm_is_final_removal failed-upgrade
check "unknown argument errs toward removal" yes webterm_is_final_removal wat

[ "$fail" -eq 0 ] || { echo "package lifecycle checks FAILED"; exit 1; }
echo "package-lifecycle-check OK"
