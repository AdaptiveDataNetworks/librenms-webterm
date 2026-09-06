# Credentials

WebTerm needs a way to authenticate to your devices. There are two drivers, and the choice is a real one.

| | Database | Vault |
|---|---|---|
| Extra infrastructure | None | A Vault deployment |
| Secret is reusable | **Yes** | No, with the SSH signer |
| Stored where | Encrypted in the LibreNMS database | Vault, or nowhere at all |
| Rotation | Manual, per device | Central, or automatic with certificates |
| Audit of credential use | WebTerm's own | Vault's audit device, independently |

## The database driver

Credentials are encrypted with a key derived from `APP_KEY` (or a dedicated `WEBTERM_CREDENTIAL_KEY`) and stored in the LibreNMS database.

It is honest to be blunt about the trade: **your monitoring database becomes a credential store.** A backup taken alongside a leaked key is a working credential for your network, and the secret works forever until someone rotates it.

That is acceptable for a small estate where the alternative is not doing this at all. It is not what you should run across hundreds of devices.

See [the database driver](db-driver.md).

## Vault

Vault can do something structurally different: sign a freshly generated public key and return a certificate valid for thirty minutes.

- Nothing reusable is stored. Not in the database, not in the gateway, not in a backup.
- The private key never leaves the gateway process.
- Devices trust a CA rather than holding per-user secrets.
- Vault's audit device records every signing request, on a system your LibreNMS administrator does not necessarily control.

For devices that cannot do certificates, Vault KV v2 still centralises the secret — better than the database driver, though the secret remains reusable while it exists.

See [Vault](../vault/index.md).

## Which method a device uses

Pinned **per target**, never inferred at connect time:

```bash
./lnms webterm:target:enable --device=core-sw-01 --principal=netops --flow=ssh_signer
```

| Flow | Meaning |
|---|---|
| `database` | Encrypted password or key from the LibreNMS database |
| `ssh_signer` | Vault-signed certificate |
| `kv2` | Password or key read from Vault KV v2 |

Inference would be a security hole rather than a convenience: a device could move silently from a 30-minute certificate to a reusable password because discovery changed its `os` field, breaking the guarantee of the whole deployment with nobody changing a setting.

The gateway enforces the same rule from its side and refuses a credential whose method does not match what was pinned.
