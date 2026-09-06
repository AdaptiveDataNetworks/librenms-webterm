# Vault policies

The least privilege WebTerm needs, and no more.

## The signer policy

For the [SSH secrets engine](ssh-engine.md):

```hcl
# librenms-webterm-signer
path "ssh-client-signer/sign/librenms-webterm" {
  capabilities = ["create", "update"]
}
```

That is the whole policy. WebTerm can ask for a certificate against **one named role**, and nothing else. It cannot read the CA private key, create roles, or sign against any other role.

The role itself is where the real constraints live — `allowed_users`, `ttl`, `default_extensions` — and WebTerm cannot change them.

## The KV policy

For [KV v2](kv-v2.md):

```hcl
# librenms-webterm-kv
path "secret/data/librenms/devices/*" {
  capabilities = ["read"]
}
```

Read only, under one prefix. WebTerm never writes secrets: rotation is your process, not ours.

!!! note "The path includes /data/"

    KV v2 policies apply to the `data/` path, not the logical one. `secret/librenms/*` looks right and grants nothing. This is the most common mistake with KV v2 policies.

## Applying them

```bash
vault policy write librenms-webterm-signer signer-policy.hcl
vault policy write librenms-webterm-kv kv-policy.hcl
```

## AppRole

If you cannot run Vault Agent:

```bash
vault auth enable approle

vault write auth/approle/role/librenms-webterm \
    token_policies="librenms-webterm-signer,librenms-webterm-kv" \
    token_ttl=1h \
    token_max_ttl=4h \
    secret_id_num_uses=0 \
    bind_secret_id=true

vault read -field=role_id auth/approle/role/librenms-webterm/role-id
vault write -f -field=secret_id auth/approle/role/librenms-webterm/secret-id
```

Store the SecretID in a file readable only by the web user:

```bash
# as root
install -m 0400 -o www-data -g www-data /dev/null /etc/librenms-webterm/vault-secret-id
printf '%s' '<secret id>' > /etc/librenms-webterm/vault-secret-id
```

```bash
# LibreNMS .env
WEBTERM_VAULT_AUTH=approle
WEBTERM_VAULT_ROLE_ID=<role id>
WEBTERM_VAULT_SECRET_ID_FILE=/etc/librenms-webterm/vault-secret-id
```

!!! warning "secret_id_num_uses"

    Set to 0 (unlimited) above. A use-limited SecretID is more secure but WebTerm cannot re-issue one for itself, so it will stop working when exhausted — usually at an inconvenient moment. If you want use limits, wrap SecretID delivery in your own configuration management, or use Vault Agent, which handles this properly.

## Vault Agent (recommended)

The agent owns authentication and renewal, and WebTerm never handles a SecretID at all:

```hcl
# /etc/vault-agent/agent.hcl
auto_auth {
  method "approle" {
    config = {
      role_id_file_path   = "/etc/vault-agent/role-id"
      secret_id_file_path = "/etc/vault-agent/secret-id"
    }
  }
}

listener "unix" {
  address     = "/run/vault-agent/agent.sock"
  tls_disable = true
  socket_mode = "0600"
  socket_user = "www-data"
}

vault { address = "https://vault.example.com:8200" }
```

```bash
# LibreNMS .env
WEBTERM_VAULT_AUTH=agent
VAULT_AGENT_ADDR=unix:///run/vault-agent/agent.sock
```

This removes an entire class of credential-handling code from a web application, which is why it is the recommendation.

## Namespaces

Vault Enterprise:

```bash
WEBTERM_VAULT_NAMESPACE=team-netops
```

WebTerm sends `X-Vault-Namespace` on everything except `sys/` endpoints, which live at the root — sending it there produces a confusing 404.
