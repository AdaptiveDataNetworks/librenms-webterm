# The admin console

Everything WebTerm does can be driven from the CLI, and for a long time that was
the only way. The console covers the same ground from LibreNMS itself — with one
deliberate exception.

Open it from **Overview → Plugins → WebTerm** — a sibling of Overview → Tools in
the same menu — or go straight to `/plugin/webterm/admin`.

!!! note "Why not the Tools menu"

    LibreNMS offers plugins exactly one navigation insertion point,
    `MenuEntryHook`, which renders into the Plugins submenu. Oxidized and the
    RIPE NCC API appear under Tools because they are hardcoded entries in core's
    menu template; there is no list a plugin can append to. Plugins sits
    directly above Tools in the same Overview dropdown, so it is the same number
    of clicks.

## What it does

| Tab | What you can do |
|---|---|
| **Targets** | Enable a device — or a whole static device group — for terminal access; set principal, flow, host key policy and algorithm profile; see why each device is enabled |
| **Credentials** | Store a password or private key against a device, a device group, or the whole fleet; remove one |
| **Access** | Create and remove grants; grant and revoke WebTerm abilities |
| **Host keys** | Read the pinned keys and their status |
| **Sessions** | See recent sessions and terminate a live one |
| **Settings** | Change the settings that take effect at runtime; see the rest read-only, with the reason |
| **Audit** | The 100 most recent events |

Devices are chosen by name everywhere, using LibreNMS's own device picker —
which filters by your device visibility, so it cannot offer a device you could
not already see.

## What it deliberately does not do

**Change host key pins or policy.** Resetting a pin and switching a target to
trust-on-first-connect are each defensible on their own, and together they amount
to turning off SSH host key verification for a device from a browser. Both stay
on the CLI: `webterm:hostkey-scan` and `webterm:hostkey-reset`.

## Enabling a device group

Enabling a group writes **one target row per member device**, tagged with the
group it came from, and the Targets table shows that provenance. It does not
make authorization consult group membership, and that difference is a security
property rather than an implementation detail.

If membership were resolved at authorization time, a device would become
shell-reachable the moment it joined a group. LibreNMS recomputes **dynamic**
group membership on every poll — `DevicePolled` fires `UpdateDeviceGroups`,
which syncs the pivot table — so a device could gain terminal access because
discovery re-detected its OS or somebody edited a `sysLocation`, with nobody
deciding anything. Even for static groups, editing the member list needs only
core's device-group update permission, which is unrelated to WebTerm's admin
ability.

So: **only static groups can be enabled**, and a dynamic group is refused with
that reason rather than silently enabling nothing. A device you configured by
hand keeps its own settings — a bulk action does not overrule a decision made
about a specific device.

Adding a device to the group later does **not** enable it. Re-run the group
enablement, which is idempotent.

## Credentials in the browser

Credential entry is available from the console. It is written to be safe rather
than merely convenient:

- The field is **write-only**. A stored secret is never rendered back to the
  page, in any tab.
- **Validation failures do not flash it.** Laravel's `withInput()` excludes only
  `password`, `password_confirmation` and `current_password`, so a field named
  anything else would land in the session store in plaintext. The controller
  validates manually and never calls `withInput()`.
- The value is marked `#[\SensitiveParameter]` where it is handled, so it cannot
  surface in a stack trace — PHP's default `zend.exception_ignore_args=Off`
  otherwise puts the first 15 characters of a string argument into any trace
  that gets logged.
- Every write and delete is audited, and the audit detail records the scope, the
  method and the login — never the secret.

The equivalent CLI commands still exist and are unchanged. They remain the only
path that requires shell access, if you would rather keep it that way: grant
nobody the `admin` ability and administer from the command line.

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
