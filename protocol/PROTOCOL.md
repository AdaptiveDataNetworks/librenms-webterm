# LibreNMS WebTerm wire protocol, version 1

**Status:** normative. `protocol/protocol.json` is the machine-readable source of truth; `src/Protocol.php` and `gateway/internal/proto/proto.go` are generated from it and CI fails if they drift.

Two independent implementations (PHP plugin, Go gateway) build against this document. Neither is permitted to infer behaviour from the other's source.

Key words **MUST**, **MUST NOT**, **SHOULD**, **MAY** are used per RFC 2119.

---

## 1. Participants and trust

| Party | Trusted with | Never holds |
|---|---|---|
| **Plugin** (PHP, in LibreNMS) | Database, credential backend, authorization decisions | The SSH transport |
| **Gateway** (Go) | SSH transport, ephemeral private keys, live sessions | DB credentials, Vault token, LibreNMS session |
| **Browser** | A single-use ticket | Any credential, ever |

### 1.1 The one-way rule

> **The plugin MUST be the only initiator of control-plane requests. The gateway MUST NOT make any outbound HTTP request to LibreNMS.**

This is normative and load-bearing. It means no credential-vending endpoint exists on the public LibreNMS vhost, and the gateway needs no LibreNMS credentials of its own. A future feature requiring gateway→plugin traffic does not get to relax this rule; it gets its own listener and its own review.

---

## 2. Transport and binding

| Listener | Default | Proxied to browser |
|---|---|---|
| Control plane | `127.0.0.1:8377` | Only `/ws` and `/ui/*` |
| Metrics | `127.0.0.1:8378` | No |

The gateway **MUST** refuse to bind a non-loopback address unless explicitly configured otherwise, and **MUST** report that state in `hello` so the plugin's doctor command can flag it.

---

## 3. Control-plane authentication

One shared secret of **32 bytes**, read by both processes **from a file path**. Implementations **MUST NOT** accept the secret from a config value, a command-line argument, an environment variable, or a database row — all of these leak into `config:cache` output, process listings, crash dumps and support bundles.

The gateway **MUST** refuse to start if the secret is absent, shorter than 32 bytes, or equal to any value published in documentation.

### 3.1 Subkey derivation

```
k_ctl = HKDF-SHA256(ikm = secret, salt = "lnms-webterm/v1", info = "control")
```

Per-purpose subkeys exist so that no endpoint can be used as a signing oracle for another. New purposes get new `info` values, never the raw secret.

### 3.2 Canonical string

```
lnms-webterm\nv1\n{METHOD}\n{PATH}\n{unix_ts}\n{nonce}\n{sha256_hex(raw_body)}
```

`{PATH}` is the path only, without query. The body hash is over the exact bytes sent, computed before any transfer encoding. An empty body hashes the empty string.

### 3.3 Headers

```
X-WebTerm-Timestamp: 1757068800
X-WebTerm-Nonce:     k7pQ2m8xR1sT4vY9
X-WebTerm-Signature: v1=<hex hmac_sha256(k_ctl, canonical)>
```

The gateway **MUST** verify in this order, rejecting at the first failure:

1. `|now - ts| <= 30` seconds.
2. `nonce` not present in a replay cache with a 120-second horizon.
3. Constant-time signature comparison.

There is deliberately **no source-address check**. It is a no-op behind a reverse proxy, and its presence would invite operators to believe a network allow-list is a security boundary when it is not.

---

## 4. The three-phase handoff

Ordering is normative. It exists so that a credential is minted **only after** a user has clicked and authorization has passed — browsing device pages mints nothing.

### Phase 1 — create pending session

`POST /api/v1/sessions`

```json
{
  "protocol": 1,
  "session_id": "01J9Z8Q4K2XW3R7T5V0B6C1D8E",
  "ticket_hash": "<sha256 hex of the 32-byte ticket>",
  "target": {
    "ip": "10.0.0.1",
    "port": 22,
    "hostname_label": "core-sw-01",
    "host_key_policy": "pin",
    "known_hosts": ["ssh-ed25519 AAAA..."],
    "algorithm_profile": "modern"
  },
  "auth": { "method": "signed_certificate", "username": "netops", "key_algorithm": "ed25519" },
  "limits": { "idle_timeout": 900, "max_duration": 14400, "warn_at": [300, 60] }
}
```

`201 Created`:

```json
{
  "session_id": "01J9Z8Q4K2XW3R7T5V0B6C1D8E",
  "instance_id": "gw-7f3a...",
  "ticket": "<43-char base64url, 32 random bytes>",
  "expires_at": "2026-09-05T12:49:56Z",
  "public_key": "ssh-ed25519 AAAAC3Nza... lnms-webterm-ephemeral"
}
```

- `target.ip` **MUST** be an IP literal. The gateway **MUST NOT** perform DNS resolution on it; no resolver may be reachable from the dial path. This is what prevents the gateway from becoming an SSRF pivot.
- `auth.username` is **server-derived** from the principal map. The gateway **MUST NOT** accept a username chosen by the browser.
- `public_key` is present only for `signed_certificate`. The corresponding **private key never leaves the gateway process**.
- The gateway **MUST** reject a request whose `auth.method` it did not advertise in `hello.capabilities`, rather than silently downgrading. **Method downgrade is a server decision only** — a gateway advertising only `password` must never receive a long-lived KV secret on a deployment whose entire claim is that no long-lived secret leaves Vault.

### Phase 2 — resolve the credential

Plugin-internal; no wire format. The plugin calls Vault (signing `public_key`, or reading KV v2) or decrypts a database row.

### Phase 3 — supply the credential

`POST /api/v1/sessions/{id}/credential`

```json
{"auth": {"method": "signed_certificate", "certificate": "ssh-ed25519-cert-v01@openssh.com AAAA...", "expires_at": "..."}}
{"auth": {"method": "password", "password": "..."}}
{"auth": {"method": "private_key", "private_key": "-----BEGIN OPENSSH PRIVATE KEY-----\n...", "passphrase": null}}
```

`204 No Content`.

This is **the only message on any wire carrying secret material.** It travels loopback, carries `Cache-Control: no-store`, and the gateway **MUST** zero the buffer once the SSH handshake completes. Implementations **MUST NOT** log the body at any level, including debug.

---

## 5. The browser connection

### 5.1 Upgrade

`GET /ws` with `Sec-WebSocket-Protocol: lnms-webterm.v1`.

Before upgrading, the gateway **MUST**:

1. Check `Origin` against a configured allow-list, **denying by default**. An empty allow-list permits nothing. SameSite cookies do **not** prevent Cross-Site WebSocket Hijacking, so this check is the control.
2. On rejection, respond `403` with `X-WebTerm-Reject: origin` **before** upgrading, so the failure is diagnosable from the browser network tab.
3. Negotiate and echo the subprotocol.

### 5.2 Authentication frame

The ticket **MUST** be presented in the **first WebSocket frame**:

```json
{"type": "auth", "ticket": "...", "cols": 120, "rows": 34}
```

The ticket **MUST NOT** appear in the URL, in a query parameter, or in a header. Query strings are written to nginx `access.log` and leak through `Referer`.

Redemption **MUST** be single-use, enforced by an atomic compare-and-swap. Two simultaneous redemptions of one ticket **MUST** result in exactly one success.

### 5.3 Frames

| Direction | Type | Fields |
|---|---|---|
| → | `auth` | `ticket`, `cols`, `rows` |
| → | `data` | `data` |
| → | `resize` | `cols`, `rows` |
| → | `ping` | — |
| ← | `ready` | `session_id`, `server_version` |
| ← | `data` | `data` |
| ← | `notice` | `level`, `message` |
| ← | `error` | `code`, `message` |
| ← | `pong` | — |

`resize` is a distinct control frame rather than an in-band escape, because the SSH `window-change` request has no representation in the byte stream.

### 5.4 Close codes

| Code | Meaning |
|---|---|
| 4400 | `protocol_error` |
| 4401 | `ticket_invalid_or_expired` |
| 4403 | `authorization_revoked` |
| 4408 | `idle_timeout` |
| 4409 | `session_superseded` |
| 4413 | `output_buffer_overrun` |
| 4429 | `concurrency_limit` |
| 4500 | `gateway_internal_error` |
| 4503 | `upstream_unreachable` |

### 5.5 Keepalive and flow control

The gateway **MUST** send a ping every **20 seconds**. This is not optional: nginx's `proxy_read_timeout` defaults to **60 seconds**, and without pings every idle terminal dies after a minute in a way that presents as an unexplained hang.

Server-to-client output is bounded at **256 KiB** buffered. On overrun the gateway **MUST** close with 4413 rather than grow without limit — a `cat` of a large file must not exhaust gateway memory. Reads from SSH **MUST** block when the buffer is full, so backpressure reaches the device rather than the gateway.

**"Idle"** is defined normatively as: no `data` frame in either direction. Pings, pongs and resizes do **not** reset the idle timer.

---

## 6. SSH behaviour

The gateway **MUST**:

- Verify the host key **before offering any authentication method**. A `pin` policy failing to match is a hard failure. `tofu_first_connect` is permitted only when explicitly configured and **MUST** be refused for any flow carrying a reusable secret (password, private key).
- Open exactly one `session` channel with a PTY.
- Refuse `direct-tcpip`, `auth-agent-req`, X11 forwarding, `exec` and `subsystem`. **Agent forwarding MUST NOT be implemented** — it would make the NMS a single point of credential theft for the entire estate.

---

## 7. Version negotiation

`GET /api/v1/hello` returns the gateway's supported range and capabilities. The plugin **MUST** compare against its own range and:

- **Intersection non-empty** → use the highest common version.
- **Empty** → disable the terminal button and surface an actionable message naming both versions. It **MUST NOT** attempt a connection.

Version skew is the steady state, not an edge case: LibreNMS's `daily.sh` re-resolves the plugin on every update while the gateway binary is untouched. Implementations support **N and N−1**.

---

## 8. Reserved

`target.protocol` is reserved for future non-SSH backends (RDP/VNC via Guacamole). Version 1 implementations **MUST** reject any value other than `ssh`.
