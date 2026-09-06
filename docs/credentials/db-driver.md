# The database credential driver

Credentials encrypted at rest in the LibreNMS database. The simple option.

## Storing a credential

```bash
# as the librenms user
./lnms webterm:credentials:set --device=core-sw-01 --username=netops
```

You are prompted for the password. It is never a command argument — arguments land in shell history and in `ps` output for every user on the machine.

For key-based authentication:

```bash
./lnms webterm:credentials:set --device=core-sw-01 --username=netops --key-file=/path/to/id_ed25519
```

## How encryption works

The key is derived from `APP_KEY` via HKDF with a distinct info string — **not** `APP_KEY` itself. `APP_KEY` protects session cookies and appears in `.env`, in deployment tooling and in support bundles; device credentials are worth more than that, so a leaked session key does not also decrypt them.

For full separation, use a dedicated key:

```bash
# in LibreNMS's .env
WEBTERM_CREDENTIAL_KEY=base64:...
```

Each stored row records the id of the key that encrypted it, which is what makes rotation survivable.

## Rotating APP_KEY

Rotating `APP_KEY` is routine advice, and without care it would silently render every stored credential undecryptable.

**Before rotating**, note the current key. **After rotating**:

```bash
# as the librenms user
./lnms webterm:credentials:rekey --from="<the old APP_KEY>" --dry-run
./lnms webterm:credentials:rekey --from="<the old APP_KEY>"
```

The rekey processes rows in chunks with independent commits, so an interruption leaves a readable mixture of old and new rather than an outage — the driver keeps reading superseded keys while a migration is in flight. One genuinely unreadable row does not abort the rest.

??? failure "Stored credential could not be decrypted"

    `APP_KEY` or `WEBTERM_CREDENTIAL_KEY` changed since the credential was saved.

    ```bash
    ./lnms webterm:credentials:rekey --from="<the old key>"
    ```

    If the old key is genuinely gone, re-enter the affected credentials:

    ```bash
    ./lnms webterm:credentials:set --device=<device> --username=<login>
    ```

## Checking

```bash
./lnms webterm:doctor
```

reports how many credentials are on the current key, and fails if any are stale.

## Moving to Vault

The drivers coexist: the flow is pinned per target, so you can migrate device by device.

```bash
./lnms webterm:config set credentials.driver vault
./lnms webterm:target:enable --device=core-sw-01 --principal=netops --flow=ssh_signer
```

Once every target has moved, delete the stored rows.
