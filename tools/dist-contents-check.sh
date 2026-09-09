#!/bin/sh
# What Packagist ships is what every LibreNMS operator gets in vendor/, and it
# is decided by .gitattributes export-ignore -- a file nobody thinks to update
# when they add something at the top level.
#
# This existed because a design-doc pass added DESIGN.md and
# PRODUCT.md, and 3,389 lines of contributor-facing notes were shipping to
# users. Nothing failed. Nothing warned. The dist just quietly grew.
#
# So: the top level of the Composer dist is an allow-list. Adding to it should
# be a deliberate act, not a side effect.
# NOTE: this reads the COMMITTED .gitattributes, because `git archive HEAD`
# does. That is the correct question -- Packagist archives a tag, not your
# working tree -- but it means an export-ignore you have edited and not yet
# committed will not show up here. Commit it, then re-run.
set -eu

cd "$(dirname "$0")/.."

ALLOWED="src config resources routes database gateway protocol packaging
composer.json LICENSE README.md CHANGELOG.md CONTRIBUTING.md CODE_OF_CONDUCT.md
SECURITY.md"

fails=0
actual=$(git archive --format=tar HEAD | tar -t | sed 's#/.*##' | sort -u | grep -v '^$')

for entry in $actual; do
    case " $(echo $ALLOWED) " in
        *" $entry "*) ;;
        *)
            echo "  FAIL: '$entry' would ship to every user's vendor/ tree." >&2
            echo "        Add it to .gitattributes as export-ignore, or to ALLOWED in" >&2
            echo "        this script if operators genuinely need it at runtime." >&2
            fails=$((fails + 1))
            ;;
    esac
done

# The reverse mistake: export-ignoring something the plugin needs to run. A
# missing view or route is a fatal on a real install and passes every test here.
for required in src resources routes composer.json; do
    printf '%s\n' "$actual" | grep -qx "$required" || {
        echo "  FAIL: '$required' is MISSING from the dist -- the plugin cannot run." >&2
        fails=$((fails + 1))
    }
done

[ "$fails" -eq 0 ] || { echo "dist-contents-check: $fails problem(s)" >&2; exit 1; }

size=$(git archive --format=tar HEAD | wc -c)
echo "dist-contents-check OK ($(printf '%s' "$actual" | wc -l) top-level entries, $((size / 1024)) KiB uncompressed)"
