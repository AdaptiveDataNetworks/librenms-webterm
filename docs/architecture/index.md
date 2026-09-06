# How it works

Two processes with a deliberately narrow contract between them.

## Components

**The plugin** — PHP, installed into LibreNMS by Composer. Decides who may connect to what, resolves credentials, mints tickets, writes audit records. It never opens an SSH connection.

**The gateway** — `librenms-webterm-gw`, a single static Go binary. Terminates the browser's WebSocket, dials SSH, verifies host keys, holds live sessions. It holds no database credentials, no Vault token, and no LibreNMS session cookie.

Splitting them this way is not architectural taste; it is forced by the platform. LibreNMS runs under PHP-FPM, which cannot hold a WebSocket open — a FastCGI worker's execution context is destroyed at the end of each request. LibreNMS also ships no queue worker, no broadcasting configuration and no WebSocket infrastructure to borrow. A separate long-lived process is the only workable answer.

## The one-way rule

**The plugin always initiates. The gateway never makes an outbound request to LibreNMS.**

The obvious design is the other way around: the browser hands a token to the gateway, the gateway calls back into LibreNMS to redeem it for a credential. We rejected that. It requires a credential-vending endpoint on your public LibreNMS vhost — an endpoint whose entire job is to hand out working credentials — and it inherits every complication of your reverse proxy, TLS termination, redirects, WAF and SSO along the way.

Instead the plugin pushes, over loopback, in three phases.

## The three-phase handoff

```mermaid
sequenceDiagram
    actor Op as Operator
    participant L as Plugin (PHP)
    participant V as Vault / database
    participant G as Gateway
    participant D as Device

    Op->>L: click "Open terminal"
    L->>L: authorize (and step-up if required)
    L->>G: 1. create pending session
    G->>G: generate ephemeral keypair
    G-->>L: ticket + public key
    L->>V: 2. resolve credential
    V-->>L: certificate / password / key
    L->>G: 3. supply credential (loopback only)
    L-->>Op: ticket
    Op->>G: WebSocket; ticket in first frame
    G->>D: verify host key, then authenticate
    D-->>G: PTY
    G-->>Op: bytes
```

Why this order matters:

- **The credential is minted after the click**, and after authorization passes. Browsing device pages mints nothing, so a page view is not a credential-issuing event.
- **The private key never crosses a process boundary.** The gateway generates it; the plugin only ever sees the public half to send to Vault.
- **Exactly one message on any wire carries secret material**, and it travels over loopback with `Cache-Control: no-store`. The gateway zeroes the buffer once the SSH handshake completes.
- **The browser only ever holds a ticket** — 32 random bytes, valid for 30 seconds, usable exactly once.

## Why the ticket is in the first frame

The ticket is sent as the first WebSocket message, never as a query parameter. Query strings are written verbatim to nginx's `access.log` and leak through `Referer` headers. A short-lived single-use ticket sitting in a log file that ships to your SIEM is a credential in a place nobody expects one.

The gateway also checks the `Origin` header against an allow-list and denies by default. `SameSite` cookies do **not** prevent cross-site WebSocket hijacking, so this check — not the cookie — is the control.

## What the gateway refuses to do

- **Resolve DNS.** It dials IP literals only, supplied by the plugin from LibreNMS's own device record. No resolver is reachable from the dial path, so the gateway cannot be steered into connecting somewhere unexpected.
- **Forward an SSH agent.** Ever. Agent forwarding into the NMS would make it a single point of credential theft for the entire estate.
- **Open anything but one PTY session channel.** `direct-tcpip`, X11, `exec` and `subsystem` are all refused, so the gateway cannot be used to tunnel.
- **Authenticate before verifying the host key.** Host key first, always — otherwise a spoofed device collects your credential.

## Further reading

- [`protocol/PROTOCOL.md`](https://github.com/adn/librenms-webterm/blob/main/protocol/PROTOCOL.md) — the normative wire specification
- [Should you enable this?](../security/index.md) — the risks this design does *not* eliminate
