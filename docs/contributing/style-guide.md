# Documentation style guide

Written before the rest of the documentation, because every other page is reviewed against it.

## Voice

Address the reader as **you**. Refer to the project as **WebTerm**, not "we" or "the plugin author". Use the imperative for instructions: "Run the command", not "You should run the command" or "We will now run the command".

Be plain about limitations. If something does not work, say so and say why. A documentation set that admits its gaps is trusted; one that quietly omits them is not.

## Commands

Every command block states **which host** and **which user** it runs as:

```bash
# LibreNMS server, as the librenms user
./lnms plugin:add adn/librenms-webterm
```

Never use `$` or `#` prompt prefixes — they break copy-paste. Never interleave output inside a command fence; show output in its own block.

## Output

**No sample output ships until it has been copy-pasted from a real run.** Invented output is worse than none: readers compare against it and conclude something is broken. CI executes the quickstart and diffs its output against this documentation.

## Failure blocks

Every anticipated failure gets a collapsible block titled with the **literal error string** the reader will see, so that pasting the error into search finds this page:

??? failure "Could not open input file: lnms"

    You are not in the LibreNMS directory. Run `cd /opt/librenms` first.

## Admonitions

- `!!! warning` — a security consequence, or something that causes data loss.
- `!!! note` — useful context the reader can skip.
- `!!! tip` — a faster path for readers who already know the basics.

Do not use admonitions for ordinary prose. If everything is highlighted, nothing is.

## Claims

Any sentence of the form "X cannot happen" must be traceable to a named test in the repository. If there is no such test, weaken the claim to describe what is actually enforced.

## Versions

Never write "the latest version". Name the version, or refer to the [compatibility matrix](../reference/compatibility.md). Readers arrive from search engines on old pages.
