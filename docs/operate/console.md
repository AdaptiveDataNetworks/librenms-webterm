# The admin console

Everything WebTerm does can be driven from the CLI, and for a long time that was
the only way. The console covers the same ground from LibreNMS itself — with one
deliberate exception.

Open it from **Overview → Plugins → WebTerm → Settings**, or go straight to
`/plugin/webterm/admin`.

## What it does

| Tab | What you can do |
|---|---|
| **Targets** | Enable and disable devices for terminal access; see which credentials exist and what each applies to |
| **Access** | Create and remove grants; grant and revoke WebTerm abilities |
| **Host keys** | Read the pinned keys and their status |
| **Sessions** | See recent sessions and terminate a live one |
| **Audit** | The 100 most recent events |

## What it deliberately does not do

**Set credentials.** Storing a device credential requires shell access to the
LibreNMS host and always will. LibreNMS is a public-facing PHP application, and
its compromise is the largest residual risk in this design — so the bar for
writing a reusable device secret is deliberately higher than an admin session in
a browser. The console shows which credentials exist and what they apply to,
because that is not a secret. Use
[`webterm:credentials:set`](../reference/cli.md#credentials).

**Change host key pins or policy.** Resetting a pin and switching a target to
trust-on-first-connect are each defensible on their own, and together they amount
to turning off SSH host key verification for a device from a browser. Both stay
on the CLI: `webterm:hostkey-scan` and `webterm:hostkey-reset`.

## Who can reach it

Only accounts holding WebTerm's own `admin` ability:

```bash
# as the librenms user
./lnms webterm:ability grant --user=jsmith --ability=admin
```

Being a LibreNMS administrator is **not** sufficient, and that is on purpose.
LibreNMS registers a `Gate::before` that returns true for every ability when a
user has the admin role, so gating the console on a core Gate ability would hand
it to every LibreNMS admin. Authorization is always WebTerm's own table.

An account without the ability gets a **404**, not a 403 — a 403 would confirm
the console exists and that the account is merely not privileged enough.

!!! warning "The console is not behind step-up"

    Step-up gates *opening a terminal*, not administering the plugin. An
    attacker holding an admin's session cookie can therefore write grants and
    abilities, though they still cannot read a stored credential and still face
    step-up before any terminal opens.

    Grant `admin` to as few accounts as you would trust with `sudo` on the
    LibreNMS host. Every console write is audited and classed security-relevant,
    so it reaches an off-box syslog stream before the local database write — see
    [Audit](audit.md) and the [threat model](../security/threat-model.md).

## Disabling it

Disabling the plugin in LibreNMS removes the console:

```bash
# as the librenms user
./lnms plugin:disable WebTerm
```

This is checked on every request rather than assumed. `lnms plugin:enable` runs
`route:cache`, and `lnms plugin:disable` updates a database column and nothing
else — so the cached route table keeps serving the paths after a disable,
including the automatic disable LibreNMS performs when a plugin hook throws. An
operator disabling the plugin to contain an incident must not be left with a live
grant-writing surface, so the check happens per request.

The global kill switch removes it too:

```bash
./lnms webterm:config set enabled false
```
