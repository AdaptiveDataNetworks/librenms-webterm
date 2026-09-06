# Quickstart

Ten minutes from nothing to a working terminal on one device, using the **database credential driver** — no Vault required. If you are deploying for an organisation, read [HashiCorp Vault](../vault/index.md) instead; this page is for evaluating.

!!! warning "Evaluate somewhere safe"

    This grants shell access from a web session. Do it on a test LibreNMS instance first, and read [Should you enable this?](../security/index.md) before doing it anywhere real.

## Before you start

- A working LibreNMS install with at least one SSH-reachable device.
- Shell access to the LibreNMS server as the `librenms` user.
- The ability to run one long-lived service on that host.

## 1. Install the plugin

```bash
# LibreNMS server, as the librenms user
cd /opt/librenms
./lnms plugin:add adn/librenms-webterm
php artisan route:clear
```

!!! failure "Error: artisan must not run as root."

    LibreNMS refuses to run as root. Run `su - librenms` first. If you already ran it as root, see [LibreNMS updates](../install/librenms-updates.md#common-failures).

Enable the plugin in the web UI under **Overview → Plugins → Plugin Admin**.

Nothing works yet — that is intentional. WebTerm ships default-deny.

## 2. Install the gateway

The gateway is a single static binary. Download it, check it, then install it:

```bash
# LibreNMS server, as root
curl -fsSLO https://github.com/adn/librenms-webterm/releases/latest/download/install.sh
less install.sh          # read it before you run it
sh install.sh --version vX.Y.Z
```

This creates a `librenms-webterm` system user, installs the binary to `/usr/bin/librenms-webterm-gw`, generates the shared secret at `/etc/librenms-webterm/gateway.secret`, and installs a systemd unit.

Start it:

```bash
# LibreNMS server, as root
systemctl enable --now librenms-webterm-gw
systemctl status librenms-webterm-gw
```

The gateway binds `127.0.0.1:8377` only. It is not reachable from outside the host, and it refuses to start without a valid 32-byte secret.

## 3. Let the browser reach it

The gateway needs one WebSocket path proxied through your existing LibreNMS vhost. For nginx, inside the LibreNMS `server` block:

```nginx
location ^~ /webterm/ws {
    proxy_pass http://127.0.0.1:8377;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_buffering off;
    proxy_read_timeout 3600s;
}
```

Three of those lines are load-bearing and are the cause of almost every "it connects then hangs" report: `proxy_http_version 1.1` (HTTP/1.0 has no `Upgrade`), `proxy_buffering off` (or output arrives in chunks and feels broken), and `proxy_read_timeout` (nginx's 60-second default kills idle terminals).

Reload nginx:

```bash
# LibreNMS server, as root
nginx -t && systemctl reload nginx
```

## 4. Point the plugin at the gateway

```bash
# LibreNMS server, as the librenms user
./lnms webterm:config set gateway.url http://127.0.0.1:8377
./lnms webterm:config set security.allowed_origins https://librenms.example.com
./lnms webterm:config set enabled true
```

Use the exact origin your browser shows, scheme included. A mismatch is rejected — deliberately, because origin checking is what prevents cross-site WebSocket hijacking.

Confirm both halves agree:

```bash
# LibreNMS server, as the librenms user
./lnms webterm:doctor
```

## 5. Add a credential and enable one device

```bash
# LibreNMS server, as the librenms user
./lnms webterm:credentials:set --device=core-sw-01 --username=netops
./lnms webterm:target:enable --device=core-sw-01
```

You are prompted for the password; it is never passed as an argument, so it stays out of your shell history and out of `ps`.

Record the device's host key:

```bash
# LibreNMS server, as the librenms user
./lnms webterm:hostkey-scan --device=core-sw-01
```

Check the fingerprint against what the device reports before accepting it. This is the one step people skip; it is also the step that stops you handing credentials to an impostor.

## 6. Grant yourself access

```bash
# LibreNMS server, as the librenms user
./lnms webterm:ability grant --user=you --ability=use
./lnms webterm:grant --user=you --device=core-sw-01
```

Shell access is deliberately separate from — and narrower than — being able to *see* a device in LibreNMS. Being an admin is not sufficient.

## 7. Open a terminal

Go to the device page for `core-sw-01`. The **Terminal** panel appears in the overview column. Click **Open terminal**, complete the TOTP step-up prompt, and you should land at a shell.

## When it does not work

```bash
# LibreNMS server, as the librenms user
./lnms webterm:why --user=you --device=core-sw-01
```

This runs the real authorization check and tells you which step failed and the exact command to fix it. Start here before reading logs.

For gateway-side problems:

```bash
# LibreNMS server, as root
journalctl -u librenms-webterm-gw -f
```

## Next steps

- [Should you enable this?](../security/index.md) — before you use this on production devices
- [HashiCorp Vault](../vault/index.md) — replace stored passwords with short-lived certificates
