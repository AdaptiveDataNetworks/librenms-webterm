# LibreNMS WebTerm

**An in-browser SSH terminal for LibreNMS devices**, opened straight from the device page — replacing the `ssh://` link that hands you off to a local client.

Credentials come from **HashiCorp Vault** (short-lived signed SSH certificates) or from **encrypted rows in the LibreNMS database**, whichever suits your shop.

[![CI](https://github.com/AdaptiveDataNetworks/librenms-webterm/actions/workflows/ci.yml/badge.svg)](https://github.com/AdaptiveDataNetworks/librenms-webterm/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/adaptivedatanetworks/librenms-webterm)](https://packagist.org/packages/adaptivedatanetworks/librenms-webterm)
[![License](https://img.shields.io/badge/license-GPL--3.0--or--later-blue)](LICENSE)

> **Status: released and in use.** Installed from a signed package repository;
> see [the roadmap](#roadmap) for what is deliberately not here yet.

---

## Read this before you install

This plugin turns your monitoring system into a jump host.

LibreNMS, by construction, has network reachability to every device it monitors — the broadest ACL in most organisations — and it is a public-facing PHP web application. Putting an interactive shell behind that front door is a real, deliberate increase in your attack surface. We think it can be done responsibly, which is why the defaults are closed and the [threat model](docs/security/threat-model.md) is published rather than buried.

**Do not install this if:**

- You cannot commit to keeping LibreNMS itself patched.
- LibreNMS is reachable from the public internet without an additional access control layer.
- You have no out-of-band access path (console server, OOB management) to your devices. This plugin fails closed when its credential backend is unreachable — precisely during the incidents when you want it most.

Read [`docs/security/index.md`](docs/security/index.md) — the "should you enable this?" guide — before enabling anything.

---

## How it works

Two processes:

- **The plugin** (PHP, installed into LibreNMS). Decides *who may connect to what*, resolves credentials, and mints a single-use ticket. It never opens an SSH connection.
- **The gateway** (`librenms-webterm-gw`, a single static Go binary). Terminates the WebSocket, dials SSH, verifies host keys. It holds no database credentials, no Vault token, and no LibreNMS session.

```
Browser ──WSS──> gateway ──SSH──> device
   │                ▲
   └──HTTPS──> LibreNMS ─┘  (loopback only, HMAC-signed)
```

The plugin always initiates. **The gateway never calls back into LibreNMS**, so there is no credential-vending endpoint on your public vhost. The private key for a certificate-based session is generated inside the gateway and never crosses a process boundary; the only message on any wire carrying secret material travels over loopback.

Full detail: [`protocol/PROTOCOL.md`](protocol/PROTOCOL.md) and [`docs/architecture/`](docs/architecture/index.md).

---

## Requirements

| | |
|---|---|
| LibreNMS | v2 plugin system (see [compatibility](docs/reference/compatibility.md)) |
| PHP | 8.2+ (matches LibreNMS core) |
| Gateway host | linux/amd64 or linux/arm64 |
| Credentials | HashiCorp Vault, **or** nothing extra for the database driver |

---

## Install

One command, on the LibreNMS server, as root:

```bash
curl -fsSLO https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/latest/download/install.sh
less install.sh          # read it before you run it
sh install.sh
```

It shows you a plan and does nothing until you say yes. Then it:

- adds the [package repository](https://adaptivedatanetworks.github.io/librenms-webterm/latest/install/package-repo/),
  after checking its signing key against the published fingerprint, and installs
  the gateway from it — so later releases arrive with your normal package updates
- installs and migrates the LibreNMS plugin as the `librenms` user, never as root
- adds the WebSocket proxy to your nginx or Apache vhost, behind a marked and
  reversible include, restoring your config untouched if the web server rejects it
- offers the SELinux boolean the gateway needs on RHEL-family hosts
- proves the whole path end to end before it exits

It asks for one thing it cannot work out: the URL your operators use to reach
LibreNMS. `APP_URL` is unset on a stock install, `base_url` is legitimately a
bare path, and `server_name` knows nothing about a TLS terminator in front of
it — and a wrong guess there means every terminal is refused with a 403 and
nothing in the logs to explain it.

Every prompt has a flag, so `--origin ... -y` runs it unattended. `--dry-run`
prints the plan and exits.

<details>
<summary>Prefer to do it by hand?</summary>

Add the repository and install the two halves yourself — see
[installing on bare metal](https://adaptivedatanetworks.github.io/librenms-webterm/latest/install/bare-metal/).
The setup helper ships in the package as `librenms-webterm-setup`, so you can
install the gateway however you like and run just the configuration half.

</details>

Nothing can open a shell yet — the plugin ships default-deny. The
[10-minute quickstart](docs/getting-started/quickstart.md) takes you from here to
a working terminal.

---

## Documentation

| | |
|---|---|
| [Quickstart](docs/getting-started/quickstart.md) | Zero to a working terminal on one device |
| [HashiCorp Vault](docs/vault/index.md) | The enterprise path, end to end |
| [Security & hardening](docs/security/index.md) | Including "should you enable this?" |
| [RBAC](docs/operate/rbac.md) | Who may open a shell on what |
| [Reverse proxy](docs/operate/reverse-proxy.md) | nginx and Apache recipes |
| [Troubleshooting](docs/gateway/troubleshooting.md) | Symptom → cause → fix |
| [LibreNMS updates](docs/install/librenms-updates.md) | **Read this before your next LibreNMS update** |

---

## Roadmap

**v1.0** — SSH. Vault (signed certificates + KV v2) and encrypted-database credential drivers. Per-device and per-group RBAC with deny precedence. TOTP step-up. Host-key pinning. Audit to the LibreNMS eventlog and off-box syslog.

**Deferred, deliberately** — session recording, RDP/VNC via Guacamole, just-in-time access approvals, per-user Vault identity. See the [threat model](docs/security/threat-model.md) and [FAQ](docs/faq.md) for why each was cut rather than rushed.

---

## Contributing

Issues and pull requests are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Security issues should **not** go in a public issue; see [SECURITY.md](SECURITY.md).

## License

GPL-3.0-or-later, matching LibreNMS. See [LICENSE](LICENSE).

This project is not affiliated with or endorsed by the LibreNMS project or HashiCorp.
