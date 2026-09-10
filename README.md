# LibreNMS WebTerm

**An in-browser SSH terminal for LibreNMS devices**, opened straight from the device page — replacing the `ssh://` link that hands you off to a local client.

Credentials come from **HashiCorp Vault** (short-lived signed SSH certificates) or from **encrypted rows in the LibreNMS database**, whichever suits your shop.

[![CI](https://github.com/AdaptiveDataNetworks/librenms-webterm/actions/workflows/ci.yml/badge.svg)](https://github.com/AdaptiveDataNetworks/librenms-webterm/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/adaptivedatanetworks/librenms-webterm)](https://packagist.org/packages/adaptivedatanetworks/librenms-webterm)
[![License](https://img.shields.io/badge/license-GPL--3.0--or--later-blue)](LICENSE)

> **Status: pre-release, under active development.** Not yet suitable for production. See [the roadmap](#roadmap).

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

Install the plugin as the `librenms` user — **not as root**, which leaves a root-owned `vendor/` and breaks later updates:

```bash
su - librenms
./lnms plugin:add adaptivedatanetworks/librenms-webterm
php artisan route:clear
```

Then enable it under **Overview → Plugins → Plugin Admin**, and install the gateway:

```bash
# Debian / Ubuntu, as root
curl -fsSL https://packages.adaptivedatanetworks.com/adn-archive-keyring.asc \
  | gpg --dearmor -o /usr/share/keyrings/adn-archive-keyring.gpg
printf 'Types: deb\nURIs: https://packages.adaptivedatanetworks.com/deb\nSuites: stable\nComponents: main\nArchitectures: amd64 arm64\nSigned-By: /usr/share/keyrings/adn-archive-keyring.gpg\n' \
  > /etc/apt/sources.list.d/adn.sources
apt update && apt install librenms-webterm-gw

# Then, either way:
librenms-webterm-setup
```

RHEL, Rocky and Alma use the matching `/etc/yum.repos.d/adn.repo` — see the
[package repository](https://adaptivedatanetworks.github.io/librenms-webterm/install/package-repo/)
page, which also carries the signing key's fingerprint.

The setup helper finds your LibreNMS install and web server, asks for the URL
your operators use, adds the WebSocket proxy to your vhost, and verifies the
result. It shows the plan before touching anything, and every prompt has a flag
for unattended runs.

Nothing can open a shell yet — the plugin ships default-deny. The [10-minute quickstart](docs/getting-started/quickstart.md) takes you from here to a working terminal.

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
