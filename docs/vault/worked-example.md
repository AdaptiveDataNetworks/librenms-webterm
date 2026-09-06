# Worked example: empty Vault to working shell

Every command, in order, from a Vault with nothing in it to an operator opening a terminal on a Linux server using a short-lived certificate.

!!! warning "Output blocks are illustrative until CI verifies them"

    This project's [style guide](../contributing/style-guide.md) says no sample output ships until it has been captured from a real run, and there is a planned CI job that executes this page against `vault server -dev` and a container running sshd. **Until that job is green, treat the output below as indicative.** Commands are correct; exact formatting may differ by Vault version.

## What you need

- A Vault you can administer (a dev server is fine for a first pass)
- A Linux host with sshd you can edit
- LibreNMS with WebTerm installed and the gateway running

## 1. Start Vault

For evaluation only — a dev server keeps everything in memory and is unsealed with a known root token:

```bash
vault server -dev -dev-root-token-id=root
```

```bash
export VAULT_ADDR=http://127.0.0.1:8200
export VAULT_TOKEN=root
```

## 2. Enable the SSH signer

```bash
vault secrets enable -path=ssh-client-signer ssh
vault write ssh-client-signer/config/ca generate_signing_key=true
```

## 3. Create the role

```bash
vault write ssh-client-signer/roles/librenms-webterm -<<'ROLE'
{
  "algorithm_signer": "rsa-sha2-512",
  "allow_user_certificates": true,
  "allowed_users": "netops",
  "default_user": "netops",
  "key_type": "ca",
  "default_extensions": { "permit-pty": "" },
  "ttl": "30m",
  "max_ttl": "60m",
  "not_before_duration": "5m"
}
ROLE
```

## 4. Trust the CA on the target host

```bash
# on the target server, as root
curl -s $VAULT_ADDR/v1/ssh-client-signer/public_key > /etc/ssh/trusted-user-ca-keys.pem
chmod 0644 /etc/ssh/trusted-user-ca-keys.pem

echo 'TrustedUserCAKeys /etc/ssh/trusted-user-ca-keys.pem' >> /etc/ssh/sshd_config
systemctl reload sshd

useradd -m -s /bin/bash netops
```

Verify sshd accepted it:

```bash
sshd -T | grep trustedusercakeys
```

## 5. Policy and AppRole

```bash
vault policy write librenms-webterm-signer -<<'POLICY'
path "ssh-client-signer/sign/librenms-webterm" {
  capabilities = ["create", "update"]
}
POLICY

vault auth enable approle

vault write auth/approle/role/librenms-webterm \
    token_policies="librenms-webterm-signer" \
    token_ttl=1h token_max_ttl=4h secret_id_num_uses=0

vault read -field=role_id auth/approle/role/librenms-webterm/role-id
vault write -f -field=secret_id auth/approle/role/librenms-webterm/secret-id
```

## 6. Configure LibreNMS

```bash
# on the LibreNMS server, as root
install -m 0400 -o www-data -g www-data /dev/null /etc/librenms-webterm/vault-secret-id
printf '%s' '<the secret id from step 5>' > /etc/librenms-webterm/vault-secret-id
```

```bash
# LibreNMS .env
WEBTERM_VAULT_ADDR=http://127.0.0.1:8200
WEBTERM_VAULT_AUTH=approle
WEBTERM_VAULT_ROLE_ID=<the role id from step 5>
WEBTERM_VAULT_SECRET_ID_FILE=/etc/librenms-webterm/vault-secret-id
```

```bash
# as the librenms user
./lnms webterm:config set credentials.driver vault
./lnms webterm:doctor
```

Expect `PASS  Credentials (vault)`. If not, see [troubleshooting](troubleshooting.md).

## 7. Enable the device

```bash
# as the librenms user
./lnms webterm:target:enable --device=server-01 --principal=netops --flow=ssh_signer
./lnms webterm:hostkey-scan --device=server-01
```

Compare the fingerprint against the server itself before accepting:

```bash
# on the target server
ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub
```

## 8. Grant access

```bash
# as the librenms user
./lnms webterm:ability grant --user=jsmith --ability=use
./lnms webterm:grant --user=jsmith --device=server-01
./lnms webterm:why --user=jsmith --device=server-01
```

`webterm:why` should report `ALLOWED`.

## 9. Open a terminal

From the device page for `server-01`, click **Open terminal**, confirm the step-up code, and you should land at a shell as `netops`.

## 10. Confirm it really was a certificate

On the target server:

```bash
journalctl -u ssh --since "5 minutes ago" | grep -i certificate
```

You should see the certificate accepted with the `key_id` WebTerm supplied:

```
Accepted publickey for netops from 10.0.0.5 port 54321 ssh2: ED25519-CERT SHA256:... ID librenms-webterm:jsmith:12 (serial 0) CA RSA SHA256:...
```

That `ID` field is the attribution — and, as [the SSH engine page](ssh-engine.md#attribution-honestly) explains, it is a claim made by your LibreNMS host, not cryptographic proof.

Nothing reusable was stored anywhere in this flow. The certificate expires in thirty minutes; the private key existed only inside the gateway process and is already gone.
