# Vault troubleshooting

## Diagnosing

```bash
# as the librenms user
./lnms webterm:doctor
```

The credentials line reports whether Vault is reachable and authenticated.

## Authentication

??? failure "Vault refused the request (403)"

    Either the token expired, or the policy does not cover the path. WebTerm re-authenticates once automatically on a 403, so a persistent 403 is a policy problem.

    ```bash
    vault token capabilities <token> ssh-client-signer/sign/librenms-webterm
    ```

    Expect `create, update`. If it says `deny`, the policy is not attached to the role, or names the wrong path.

??? failure "WEBTERM_VAULT_ROLE_ID is not set"

    ```bash
    vault read -field=role_id auth/approle/role/librenms-webterm/role-id
    ```

    Put it in LibreNMS's `.env` and clear the config cache.

??? failure "The AppRole SecretID file ... is not readable by the web user"

    The file must be readable by the user php-fpm runs as:

    ```bash
    # as root
    chown www-data /etc/librenms-webterm/vault-secret-id
    chmod 0400 /etc/librenms-webterm/vault-secret-id
    ```

??? failure "Vault Agent did not provide a usable token"

    The agent is not running, or its socket is not readable:

    ```bash
    systemctl status vault-agent
    ls -l /run/vault-agent/agent.sock
    ```

    The socket needs `socket_user` set to the web user and `socket_mode = "0600"`.

## Sealed or unavailable

??? failure "Vault is sealed or standby (503)"

    WebTerm fails closed: no terminal opens until Vault is available. This is deliberate, and it is why [out-of-band device access is a prerequisite](../security/index.md).

    ```bash
    vault status
    vault operator unseal
    ```

## Certificates

??? failure "Vault returned no signed certificate"

    Usually the role does not permit the principal WebTerm asked for. Check `allowed_users`:

    ```bash
    vault read ssh-client-signer/roles/librenms-webterm
    ```

    The target's principal must appear there:

    ```bash
    ./lnms webterm:target:enable --device=server-01 --principal=netops --flow=ssh_signer
    ```

??? failure "The certificate is signed but the device rejects it"

    Work through these in order:

    **Does sshd trust the CA?**

    ```bash
    sshd -T | grep trustedusercakeys
    ```

    **Does the CA key match the one Vault is using?**

    ```bash
    ssh-keygen -lf /etc/ssh/trusted-user-ca-keys.pem
    vault read -field=public_key ssh-client-signer/config/ca | ssh-keygen -lf -
    ```

    **Is the device's clock right?** A device running fast rejects a certificate that is not yet valid. `not_before_duration: 5m` in the role tolerates ordinary skew; a badly wrong clock defeats it.

    ```bash
    # on the device
    timedatectl
    ```

    **Does the device support OpenSSH certificates at all?** Some vendors implement X.509-based SSH certificates that are not interchangeable. If so, use [KV v2](kv-v2.md) for that device.

    **What does the device's own log say?** This is usually the fastest answer:

    ```bash
    journalctl -u ssh --since "5 minutes ago"
    ```

## KV v2

??? failure "No secret at secret/data/librenms/devices/42"

    The message includes the exact command to create it. Note that `vault kv put` takes the logical path, without `/data/`:

    ```bash
    vault kv put secret/librenms/devices/42 username=netops password='...'
    ```

??? failure "The secret at ... has no \"password\" field"

    Either the field is named something else, or the secret holds a key instead:

    ```bash
    ./lnms webterm:config set credentials.vault.kv2.field_map.password pass
    ```

??? failure "Cannot build a Vault path"

    A template placeholder resolved to something outside `^[A-Za-z0-9._-]{1,64}$`. Hostnames with dots are fine; slashes and spaces are not. `{device_id}` is always safe.

## Namespaces

If every request 404s on Vault Enterprise, check the namespace:

```bash
./lnms webterm:config get credentials.vault.namespace
```

WebTerm deliberately omits the namespace header on `sys/` endpoints, which live at the root.
