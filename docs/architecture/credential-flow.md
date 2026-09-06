# How a credential reaches a device

The most security-critical path in the system, in detail.

## The sequence

```mermaid
sequenceDiagram
    autonumber
    actor Op as Operator
    participant B as Browser
    participant L as LibreNMS plugin
    participant V as Vault / database
    participant G as Gateway
    participant D as Device

    Op->>B: click "Open terminal"
    B->>L: POST /plugin/webterm/session
    L->>L: authorize; challenge step-up if required

    rect rgb(238,244,255)
    Note over L,G: Phase 1 — loopback, HMAC-signed
    L->>G: create pending session (target, policy, ticket hash)
    G->>G: generate an ephemeral keypair
    G-->>L: ticket + public key
    end

    rect rgb(238,255,244)
    Note over L,V: Phase 2 — the credential is minted only now
    L->>V: sign(public key) / read secret / decrypt row
    V-->>L: certificate | password | private key
    end

    rect rgb(238,244,255)
    Note over L,G: Phase 3 — the ONLY message carrying a secret
    L->>G: credential
    G-->>L: 204
    end

    L-->>B: ticket (no credential)
    B->>G: WebSocket; ticket in the first frame
    G->>G: burn the ticket (single-use, CAS)
    G->>D: verify host key, then authenticate
    D-->>G: PTY
    G-->>B: bytes
```

## Why this order

**The credential is minted after the click**, and after authorization. Browsing device pages mints nothing, so a page view is not a credential-issuing event and a curious user cannot cause certificates to be signed by scrolling.

**The private key never crosses a process boundary.** The gateway generates it; the plugin only ever handles the public half. There is no long-lived private key anywhere to steal, and a database compromise yields nothing that can authenticate.

**Exactly one message on any wire carries secret material**, and it travels over loopback with `Cache-Control: no-store`. The gateway zeroes the buffer once the handshake completes.

**The browser only ever holds a ticket** — 32 random bytes, valid 30 seconds, usable once.

## The one-way rule

> The plugin always initiates. The gateway never makes an outbound request to LibreNMS.

The obvious design is the reverse: the browser hands a token to the gateway, and the gateway calls back to redeem it. We rejected that, because it requires an endpoint on your **public** LibreNMS vhost whose entire job is handing out working credentials — and that endpoint then inherits every complication of your reverse proxy, TLS termination, redirects, WAF and SSO.

Reversing the direction removes the endpoint entirely. The gateway needs no LibreNMS credentials, and session state is reconciled by polling instead.

## Why the ticket is in the first frame

Never in the URL. Query strings are written verbatim to nginx access logs and leak through `Referer` headers. A short-lived single-use credential sitting in a log file that ships to your SIEM is a credential in a place nobody expects one.

## Where each secret exists, and for how long

| Secret | Exists in | Lifetime |
|---|---|---|
| Ticket | Gateway memory (hashed), browser | 30 seconds, single use |
| Ephemeral private key | Gateway memory only | The session |
| Certificate | Plugin briefly, gateway | 30 minutes |
| Stored password | Database (encrypted), briefly in plugin and gateway memory | Until rotated |
| Vault token | Plugin cache (encrypted) | 75% of its lease |
| Shared secret | A file on disk, both processes | Until rotated |

## What is verified before a credential is offered

1. The address is an IP literal, and not loopback, link-local, multicast or unspecified.
2. The TCP connection succeeds.
3. **The host key matches a pin.** Go runs the host key callback during key exchange, before any authentication method is offered, so a device presenting an unexpected key never sees the credential.

Only then is authentication attempted.
