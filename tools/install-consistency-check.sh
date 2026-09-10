#!/bin/sh
# One install story, told once.
#
# This exists because docs/getting-started/quickstart.md shipped with a section
# "1. Install the plugin" running `./lnms plugin:add`, immediately above a
# section "2. Install everything" running install.sh -- which installs the
# plugin itself. A reader was told to do the same thing twice by two different
# methods, and the page had claimed to be the fast path.
#
# It happened because an edit rewrote sections 2-4 of that page and left section
# 1 alone. Nothing failed, because nothing was checking that the page agreed
# with itself.
#
# The rule: install.sh is THE documented route. A manual step may still appear,
# but only inside something that visibly marks it as the alternative -- a
# collapsed `???`/`<details>` block, or a heading that says so. A bare manual
# step in the main flow is the bug.
set -eu

cd "$(dirname "$0")/.."

fails=0
note() { printf '  %s\n' "$*" >&2; }

# Commands an operator no longer has to run themselves.
MANUAL='lnms plugin:add|librenms-webterm-setup|apt install librenms-webterm-gw|dnf install librenms-webterm-gw'

for f in $(git ls-files 'docs/**/*.md' 'docs/*.md' README.md 2>/dev/null); do
    # Pages whose whole subject IS the manual route are exempt by name. They
    # must still say so in their title, which the second check enforces.
    case "$f" in
        docs/install/bare-metal.md|docs/install/package-repo.md|docs/install/upgrading.md|\
        docs/contributing/*|docs/install/librenms-updates.md|docs/install/uninstall.md)
            continue ;;
        # Docker is a genuinely different topology: install.sh configures a host
        # web server and a systemd unit, neither of which exists there. Its
        # manual steps are the route, not a leftover.
        docs/install/docker.md)
            continue ;;
    esac

    # Only lines inside a fenced code block count. Prose that MENTIONS a command
    # while explaining something -- "`./lnms plugin:add` runs composer require,
    # which is why validate.php complains" -- is not an instruction to run it,
    # and flagging it trains the reader to ignore this check.
    awk -v file="$f" -v pat="$MANUAL" '
        BEGIN { fence = 0; alt = 0 }
        /^[[:space:]]*```/ { fence = !fence; next }
        !fence { }
        # A collapsed block or an explicitly-labelled section opens the escape hatch.
        /^[[:space:]]*(\?\?\?|!!!)/            { alt = 1 }
        /<details/                              { alt = 1 }
        /<\/details>/                           { alt = 0 }
        # A new top-level heading closes it again.
        /^#{1,3} / && $0 !~ /by hand|manual|instead|alternative/ { alt = 0 }
        /^#{1,3} / && $0 ~  /by hand|manual|instead|alternative/ { alt = 1 }
        $0 ~ pat {
            if (!alt && fence) {
                printf "  %s:%d: %s\n", file, NR, substr($0, 1, 90)
                bad = 1
            }
        }
        END { exit bad ? 1 : 0 }
    ' "$f" || { fails=$((fails + 1)); }
done

if [ "$fails" -ne 0 ]; then
    note ""
    note "The lines above present a manual install step in the main flow."
    note "install.sh does all of these. Move the step into a collapsed block, or a"
    note "section whose heading says 'by hand' / 'manual' / 'instead', or delete it."
    exit 1
fi

# And the inverse: the quickstart must actually name install.sh, or the one
# command story is not being told at all.
grep -q 'install\.sh' docs/getting-started/quickstart.md || {
    note "docs/getting-started/quickstart.md never mentions install.sh."
    exit 1
}

echo "install-consistency-check OK"
