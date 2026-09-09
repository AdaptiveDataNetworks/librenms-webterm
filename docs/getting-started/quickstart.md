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
./lnms plugin:add adaptivedatanetworks/librenms-webterm
php artisan route:clear
```

!!! failure "Error: artisan must not run as root."

    LibreNMS refuses to run as root. Run `su - librenms` first. If you already ran it as root, see [LibreNMS updates](../install/librenms-updates.md#common-failures).

Enable the plugin in the web UI under **Overview → Plugins → Plugin Admin**.

Nothing works yet — that is intentional. WebTerm ships default-deny.

## 2. Install the gateway

=== "Debian / Ubuntu"

    ```bash
    # LibreNMS server, as root
    curl -fsSLO https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/download/vX.Y.Z/librenms-webterm-gw_X.Y.Z_linux_amd64.deb
    apt install ./librenms-webterm-gw_X.Y.Z_linux_amd64.deb
    ```

=== "RHEL / Rocky / Alma"

    ```bash
    # LibreNMS server, as root
    curl -fsSLO https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/download/vX.Y.Z/librenms-webterm-gw_X.Y.Z_linux_amd64.rpm
    dnf install ./librenms-webterm-gw_X.Y.Z_linux_amd64.rpm
    ```

=== "No packages"

    ```bash
    # LibreNMS server, as root
    curl -fsSLO https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/latest/download/install.sh
    curl -fsSLO https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/latest/download/webserver.sh
    less install.sh          # read it before you run it
    sh install.sh --version vX.Y.Z
    ```

    Both files, in the same directory: `install.sh` sources `webserver.sh` for
    the proxy step and skips it if it is not there.

The binary lands at `/usr/bin/librenms-webterm-gw` and binds `127.0.0.1:8377`
only. It is not reachable from outside the host, and it refuses to start
without a valid 32-byte secret.

It is deliberately **not** started yet. A gateway running before its allowed
origins are set refuses every browser connection with a 403 and looks broken.

## 3. Wire it up

```bash
# LibreNMS server, as root
librenms-webterm-setup
```

This is the rest of the install in one command: it finds your LibreNMS
directory and web server, asks for the URL your operators use, adds the proxy
to your vhost, shares the gateway secret with the LibreNMS user, starts the
gateway, and checks its own work.

It shows you the plan and does nothing until you say yes, backs up your vhost
before touching it, and restores it if your web server rejects the result.
Every prompt has a flag for unattended runs, and `--dry-run` prints the plan
and exits.

!!! note "It will ask for your LibreNMS URL"

    That one cannot be inferred. `APP_URL` is unset on a stock LibreNMS and
    reads back as `http://localhost`, `base_url` is legitimately a bare path,
    and `server_name` knows nothing about a TLS terminator in front of it. The
    browser sends the origin *it* used, so that is the one the gateway must be
    told about — scheme included. A mismatch is rejected deliberately: origin
    checking is what prevents cross-site WebSocket hijacking.

## 4. Confirm both halves agree

```bash
# LibreNMS server, as the librenms user
./lnms webterm:doctor
```

The setup helper already ran this and exited with its status, so if it finished
cleanly there is nothing to do here.

??? info "Doing steps 3 and 4 by hand"

    Every step, written out — the reverse-proxy stanzas, the secret's group
    permissions, SELinux, and what to set where — is in
    [installing on bare metal](../install/bare-metal.md#doing-it-by-hand).
    The [reverse proxy](../operate/reverse-proxy.md) page explains which lines
    are load-bearing and what breaks without each one.

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

!!! warning "Step-up needs LibreNMS two-factor enrolled"

    Step-up is required by default, and it is satisfied only by LibreNMS's own
    TOTP. If your account has no two-factor enrolled you will be denied with
    `step_up_required` no matter what else is configured.

    Enrol TOTP in LibreNMS under **Preferences → Two-Factor Auth**, or, if you
    have decided this control is not for you, turn it off:

    ```bash
    # as the librenms user
    ./lnms webterm:config set security.step_up false
    ```

    `./lnms webterm:doctor` reports which of the two you are in.

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
