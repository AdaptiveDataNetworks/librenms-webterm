# Who may open a shell

WebTerm's permissions are **separate from, and narrower than, LibreNMS's own**.

That is deliberate. LibreNMS's `DevicePolicy::view()` returns true for anyone with the `global-read` permission, and for any user with the plain `user` role on their assigned devices. Treating that as permission to open a shell would hand a terminal to every read-only account in your organisation.

Being a LibreNMS administrator grants **no** terminal access on its own.

## The three things a user needs

All three, or the terminal button does not appear:

1. **Visibility.** They must be able to see the device in LibreNMS. Enforced by LibreNMS, not by us — there is a property test asserting we never admit anyone to a device LibreNMS would hide from them.
2. **The `use` ability.**
3. **An allow grant** covering that device, and no matching deny.

Plus the device must be an enabled target, and step-up must be satisfied.

```bash
# as the librenms user
./lnms webterm:ability grant --user=jsmith --ability=use
./lnms webterm:grant --user=jsmith --device=core-sw-01
```

When something is refused, ask:

```bash
./lnms webterm:why --user=jsmith --device=core-sw-01
```

That runs the real authorization path and prints the command that fixes the failing step.

## Abilities

| Ability | Grants |
|---|---|
| `use` | May open terminals, subject to grants |
| `admin` | May manage targets, grants and host keys |
| `audit.view` | May read the audit trail |

## Grants

A grant has a **subject** (a user, or a LibreNMS role) and an **object** (a device, or a static device group).

```bash
# a user, one device
./lnms webterm:grant --user=jsmith --device=core-sw-01

# a role, a whole group
./lnms webterm:grant --role=netops --group=4

# time-bounded, for a change window
./lnms webterm:grant --user=contractor --device=edge-01 --until="2026-09-30 18:00"

# revoke
./lnms webterm:grant --user=jsmith --device=core-sw-01 --remove
```

Roles are referenced **by name**, not by id: Spatie role ids are assigned per install, so a documented grant naming role 3 means something different on another system.

### Deny always wins

```bash
./lnms webterm:grant --user=jsmith --device=core-sw-01 --deny
```

A deny beats every allow, whatever its subject or object. This is operational, not theoretical: revoking someone's access during an incident must be one command, not a hunt for every allow — user, role, device and group — that might match.

A deny outside its time window is not a deny, so an expired maintenance freeze does not lock people out permanently.

### Limits intersect

Where several allows match, the **tightest** limit wins. Adding a broader grant can never relax a restriction set deliberately on a narrower one.

## Only static device groups

Dynamic groups are rule-driven, which means a device can join one — and acquire shell access — because someone edited its `sysLocation`. Access should change when a human changes an access rule, so only static groups are honoured.

## Revocation is immediate-ish

Once a minute the reconciler re-checks live sessions, from LibreNMS's own scheduler (`dist/librenms-scheduler.cron`, which runs `artisan schedule:run`). Removing a grant ends the running terminal, not merely the next one.

```bash
./lnms webterm:sessions
./lnms webterm:sessions --kill=01J9Z8... --reason="access review"
```
