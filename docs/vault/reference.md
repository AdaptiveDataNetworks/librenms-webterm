# Vault reference

## Configuration

| Setting | Environment | Default |
|---|---|---|
| Address | `WEBTERM_VAULT_ADDR` | `http://127.0.0.1:8200` |
| Namespace | `WEBTERM_VAULT_NAMESPACE` | *(none)* |
| Auth method | `WEBTERM_VAULT_AUTH` | `agent` |
| Agent socket | `VAULT_AGENT_ADDR` | `unix:///run/vault-agent/agent.sock` |
| AppRole mount | `WEBTERM_VAULT_APPROLE_MOUNT` | `approle` |
| AppRole id | `WEBTERM_VAULT_ROLE_ID` | *(none)* |
| SecretID file | `WEBTERM_VAULT_SECRET_ID_FILE` | *(none)* |
| Signer mount | `WEBTERM_VAULT_SSH_MOUNT` | `ssh-client-signer` |
| Signer role | `WEBTERM_VAULT_SSH_ROLE` | `librenms-webterm` |
| KV mount | `WEBTERM_VAULT_KV_MOUNT` | `secret` |
| KV path template | `WEBTERM_VAULT_KV_PATH` | `librenms/devices/{device_id}` |
| TLS verification | `WEBTERM_VAULT_TLS_VERIFY` | `true` |
| CA bundle | `WEBTERM_VAULT_CACERT` | *(system)* |

## Endpoints WebTerm calls

| Method | Path | When |
|---|---|---|
| `POST` | `/v1/auth/{mount}/login` | AppRole authentication |
| `GET` | `/v1/auth/token/lookup-self` | Agent authentication |
| `POST` | `/v1/{mount}/sign/{role}` | Signing a certificate |
| `GET` | `/v1/{mount}/data/{path}` | Reading a KV v2 secret |

Nothing else. WebTerm never writes a secret, never creates a role, and never reads the CA private key.

## Token handling

- Cached, always encrypted, for 75% of its lease.
- Obtained under a lock with a double-check, so a burst of connections on a cold cache performs one login rather than many.
- Re-authenticated exactly once on a 403, then failed. Retrying indefinitely would turn a misconfigured policy into a login storm.

## Certificate parameters

| Parameter | Value | Why |
|---|---|---|
| `valid_principals` | the target's pinned principal | Server-derived; never from the browser |
| `ttl` | 30m | Bounds a leaked certificate |
| `key_id` | `librenms-webterm:<user>:<device_id>` | Attribution, asserted not proved |

## What the capabilities matrix looks like

| | Database | Vault KV v2 | Vault signer |
|---|---|---|---|
| Password | Yes | Yes | No |
| Private key | Yes | Yes | No |
| Signed certificate | **No** | No | **Yes** |
| Secret is reusable | Yes | Yes | No |
| Central rotation | No | Yes | N/A |
| Independent audit | No | Yes | Yes |

A provider that cannot issue a certificate says so, and the connect path refuses to downgrade — which is what stops a certificate-only deployment quietly falling back to a password.
