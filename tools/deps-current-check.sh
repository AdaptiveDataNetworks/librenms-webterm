#!/bin/sh
# Analyse with the tools CI will actually use.
#
# composer.lock is gitignored on purpose -- this is a library, and a library that
# ships a lock imposes its dependency choices on the application installing it.
# The consequence is that CI resolves dev dependencies FRESH on every run, while
# a developer's vendor/ stays pinned by a lock only they have.
#
# So static analysis locally and static analysis in CI are not the same analysis.
# That is not hypothetical: v1.1.3 was tagged on a red CI because larastan 3.12
# landed between two runs and found a real issue that larastan 3.11 -- still
# installed here -- did not. No PHP file had changed between the passing and
# failing commits. preflight said OK; CI said no.
#
# This makes that divergence visible BEFORE the gate passes, rather than after a
# tag is pushed.
set -eu

cd "$(dirname "$0")/.."

out=$(composer update --dry-run --no-interaction --no-scripts 2>&1 || true)

# Composer says this, verbatim, when the installed set already matches a fresh
# resolve. Anything else means CI is about to analyse with different tools.
if printf '%s' "$out" | grep -q "Nothing to install, update or remove"; then
    echo "deps-current-check OK: vendor matches a fresh resolve"
    exit 0
fi

changes=$(printf '%s\n' "$out" | grep -E "^\s*- (Upgrading|Downgrading|Installing|Removing)" || true)

echo "" >&2
echo "deps-current-check: your vendor/ is not what CI will resolve." >&2
echo "" >&2
if [ -n "$changes" ]; then
    printf '%s\n' "$changes" | sed 's/^/  /' >&2
else
    printf '%s\n' "$out" | tail -12 | sed 's/^/  /' >&2
fi
echo "" >&2
echo "CI runs composer install with no committed lock, so it resolves fresh every" >&2
echo "time. Static analysis here is therefore weaker than static analysis there," >&2
echo "and a green preflight can sit on top of a red CI -- which is how v1.1.3 was" >&2
echo "tagged." >&2
echo "" >&2
echo "  composer update" >&2
echo "" >&2
echo "then re-run preflight. If an updated tool finds something, that is the point." >&2
exit 1
