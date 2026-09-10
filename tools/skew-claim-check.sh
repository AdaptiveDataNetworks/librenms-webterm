#!/bin/sh
# The version-skew promise lives in more than one place, and it drifted once.
#
# The project used to claim plugin and gateway tolerated "one protocol version of
# skew in each direction". They do not: any mismatch is refused. Commit b0b82d7
# corrected the docs, the compatibility matrix and the release footer -- and
# missed CHANGELOG.md, which went on promising skew tolerance directly above the
# release notes that contradicted it.
#
# An operator reads that claim and decides whether it is safe to upgrade one half
# without the other. So it is worth a check rather than a habit.
set -eu

cd "$(dirname "$0")/.."

fails=0
# Phrasings that assert tolerance. Anything matching is a claim we cannot keep.
# The hyphen class matters. This check passed for a day while three claims were
# live, because it matched ASCII "N-1" and every surviving claim was written with
# U+2212 MINUS SIGN (N-1) by a Markdown editor. Match both, plus the en dash.
if git grep -n -i -E 'skew (of|in each direction)|one version (apart|of skew)|N ?/ ?N[-\xe2\x80\x93\xe2\x88\x92]1|tolerat[a-z]* (one|a) (protocol|version)|version apart|Further apart' \
        -- ':!tools/skew-claim-check.sh' 2>/dev/null; then
    echo "" >&2
    echo "FAIL: the text above claims version-skew tolerance." >&2
    echo "There is none: any protocol mismatch is refused (GatewayVersionException)." >&2
    echo "See docs/reference/compatibility.md for the wording that is true." >&2
    fails=1
fi

# And the claim that IS true must actually still be documented somewhere.
git grep -q -i "no skew tolerance" -- CHANGELOG.md docs/ || {
    echo "FAIL: nothing states the no-skew-tolerance rule any more." >&2
    echo "It is what an operator needs before upgrading one half without the other." >&2
    fails=1
}

[ "$fails" -eq 0 ] || exit 1
echo "skew-claim-check OK"
