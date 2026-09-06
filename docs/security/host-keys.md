# Host keys

Every SSH library in common use verifies nothing by default. WebTerm's trust store is therefore entirely its own.

## Why this matters more here

An operator using `ssh` from a laptop has a `known_hosts` file built up over time, and gets a loud warning when a key changes. WebTerm connects from a server, on behalf of a user who never sees a prompt. Without pinning there would be no verification at all, and the first thing a substituted device would receive is a valid credential.

## Pinning

```bash
# as the librenms user
./lnms webterm:hostkey-scan --device=core-sw-01
```

The gateway connects far enough to see the key and stops — it **never offers an authentication method during a scan**, because the scan happens before anyone has decided to trust the device.

The fingerprint is printed and you are asked to confirm. Compare it against the device itself:

```bash
# on the device
ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub
```

Pinning a key nobody checked is trust-on-first-use with extra steps.

## Policies

| Policy | Behaviour |
|---|---|
| `pin` (default) | A pinned key must exist and match, or the connection fails |
| `tofu_first_connect` | The first connection records the key, subsequent ones must match |

```bash
./lnms webterm:target:enable --device=core-sw-01 --principal=netops --policy=pin
```

### Trust-on-first-use is refused for reusable secrets

Even with `tofu_first_connect`, WebTerm will not pin on first connect when the credential is a password or a private key.

First-connect trust means handing the credential to whatever answers. With a 30-minute certificate that exposure is bounded and the trade is defensible. A password given to an impostor is a lasting compromise of that device, and no amount of later pinning undoes it.

When TOFU does apply, the key is recorded **before** connecting, so the session itself runs against a pinned key like any other.

## When a key changes

The connection fails, hard, and the user sees:

> The device presented a different SSH host key than the one pinned. This is either a legitimate key change or an interception; an administrator must review it.

**Do not clear the pin reflexively.** Establish why it changed:

- A rebuild or firmware upgrade regenerates host keys. Expected.
- A device replaced under RMA. Expected.
- Nothing changed, as far as anyone knows. **Stop and investigate.**

Once satisfied:

```bash
./lnms webterm:hostkey-reset --device=core-sw-01 --reason="firmware upgrade, change CHG-1234"
./lnms webterm:hostkey-scan --device=core-sw-01
```

The reason is mandatory and is written to the audit trail. Clearing a pin is exactly what an attacker would want done after substituting a device, so the record of who did it and why is the control.

The old key is kept as `superseded` rather than deleted, because someone reviewing the change needs to see what it was before.

## Bulk operations

```bash
# scan every enabled target
./lnms webterm:hostkey-scan

# check which pinned targets are missing a key
./lnms webterm:doctor --deep
```

## What is stored

Only the base64 key blob, never the whole `authorized_keys` line. The comment field is attacker-controlled text from the far end and would otherwise be rendered in the admin UI.
