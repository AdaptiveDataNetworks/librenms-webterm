# Requirements

## LibreNMS

WebTerm needs the LibreNMS **v2 plugin system** — the Composer-package architecture built on Laravel package auto-discovery. See [compatibility](../reference/compatibility.md).

You will also need shell access to the LibreNMS server as the `librenms` user, and the ability to run one long-lived service on some host that can reach your devices.

## The gateway host

The gateway is a single static binary for **linux/amd64** or **linux/arm64**. It needs no runtime, no interpreter and no shared libraries.

It should normally run **on the LibreNMS server itself**, because the device reachability WebTerm depends on is LibreNMS's reachability. Running it elsewhere is supported — set `gateway.url` accordingly — but then that host needs the same network access, and the control plane between them stops being loopback traffic.

!!! warning "Co-location has a consequence worth naming"

    With the gateway on the LibreNMS server, a compromise of either is a compromise of both. That is the trade for a one-command install, and for most installations it is the right one — LibreNMS already has the network access that matters. If your threat model says otherwise, run the gateway on a separate host and put a private network between them.

Resource use is modest: a few MB per idle session, dominated by buffers rather than the process itself. The shipped systemd unit caps memory at 512 MB and 512 tasks.

## Network

| From | To | Why |
|---|---|---|
| Browser | LibreNMS (443) | The UI and the proxied WebSocket |
| LibreNMS | Gateway (8377) | Control plane — loopback when co-located |
| Gateway | Devices (22) | The SSH sessions themselves |
| LibreNMS | Vault (8200) | Only with the Vault credential driver |

The gateway binds loopback by default and **refuses to start** on a non-loopback address unless you explicitly override it. The browser reaches it through your existing LibreNMS reverse proxy, not directly.

## Credentials

Either:

- **Nothing extra** for the database driver — credentials are encrypted in the LibreNMS database. Fine for a small estate; read the honest caveats in [Should you enable this?](../security/index.md).
- **A reachable HashiCorp Vault** for the enterprise path. See [Vault](../vault/index.md).

## Two-factor

Step-up authentication is on by default and reuses the TOTP enrolment LibreNMS already stores. At least one operator must be enrolled in LibreNMS's own two-factor before anyone can open a terminal — or you must turn step-up off deliberately, which the documentation would rather you did not.
