# Vault KV v2

For devices that cannot use certificates.

The secret is still reusable while it exists, so this is not equivalent to the [SSH signer](ssh-engine.md). It is better than the database driver — central rotation, real audit, one place to revoke — and it is the right answer for equipment that leaves you no choice.

## Setting it up

```bash
vault secrets enable -path=secret -version=2 kv

vault kv put secret/librenms/devices/42 \
    username=netops \
    password='...'
```

The path is a template; `42` above is the LibreNMS device id.

## Configuring the path

```bash
# as the librenms user
./lnms webterm:config set credentials.vault.kv2.path_template 'librenms/devices/{device_id}'
```

Three placeholders are available, and only three:

| Placeholder | Value |
|---|---|
| `{device_id}` | LibreNMS device id |
| `{hostname}` | Device hostname |
| `{principal}` | The SSH username being connected as |

Each is validated against `^[A-Za-z0-9._-]{1,64}$` and URL-encoded, and the result is rejected if it contains `..`, `//`, `?` or `#`.

!!! note "Why the template language is this small"

    A hostname is attacker-influenceable in some environments — it can come from discovery. A richer template language over a secret store is a path-traversal surface, so WebTerm accepts three known values and validates every one.

    `{device_id}` is the safest choice: it is always an integer under LibreNMS's control.

## Field names

```bash
./lnms webterm:config set credentials.vault.kv2.field_map.password password
./lnms webterm:config set credentials.vault.kv2.field_map.private_key private_key
```

If the secret contains a `private_key` field it is used in preference to a password, with an optional `passphrase`.

## The /data/ segment

KV v2 reads go through `/data/`:

```
secret/librenms/devices/42          <- what you write with `vault kv put`
secret/data/librenms/devices/42     <- what the HTTP API reads
```

WebTerm inserts `/data/` itself, so configure the **logical** path. Your Vault policy, however, must grant on the `data/` path — see [policies](policies.md).

## Enabling a device

```bash
./lnms webterm:target:enable --device=old-switch --principal=admin --flow=kv2
```

## Rotation

Rotate in Vault; WebTerm reads the current version on every connection and never caches a secret.

```bash
vault kv put secret/librenms/devices/42 username=netops password='<new>'
```

No WebTerm change is needed, and existing sessions are unaffected.
