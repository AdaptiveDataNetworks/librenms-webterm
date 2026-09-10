# Quickstart

Ten minutes from nothing to a working terminal on one device, using the **database credential driver** — no Vault required. If you are deploying for an organisation, read [HashiCorp Vault](../vault/index.md) instead; this page is for evaluating.

!!! warning "Evaluate somewhere safe"

    This grants shell access from a web session. Do it on a test LibreNMS instance first, and read [Should you enable this?](../security/index.md) before doing it anywhere real.

## Before you start

- A working LibreNMS install with at least one SSH-reachable device.
- Shell access to the LibreNMS server as the `librenms` user.
- The ability to run one long-lived service on that host.

## 1. Install everything

```bash
# LibreNMS server, as root
curl -fsSLO https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/latest/download/install.sh
less install.sh          # read it before you run it
sh install.sh
```

It shows a plan and does nothing until you say yes. Then it adds the
[package repository](../install/package-repo.md) — after checking its signing key
against the published fingerprint — installs the gateway from it, installs and
migrates the plugin as the `librenms` user, adds the WebSocket proxy to your
vhost, offers the SELinux boolean on RHEL-family hosts, and verifies the whole
path before it exits.

Installing the gateway from the repository rather than a downloaded file is what
makes `apt upgrade` and `dnf upgrade` pick up later releases on their own.

!!! note "It will ask for your LibreNMS URL"

    That one cannot be inferred. `APP_URL` is unset on a stock LibreNMS and reads
    back as `http://localhost`, `base_url` is legitimately a bare path, and
    `server_name` knows nothing about a TLS terminator in front of it. The browser
    sends the origin *it* used, so that is the one the gateway must be told about —
    scheme included. A mismatch is rejected deliberately: origin checking is what
    prevents cross-site WebSocket hijacking.

Every prompt has a flag, so it runs unattended:

```bash
# LibreNMS server, as root
sh install.sh --origin https://librenms.example.com -y
```

`--dry-run` prints the plan and exits. The exit code is `webterm:doctor`'s, so it
is safe to gate a playbook on.

??? info "Doing it in pieces instead"

    Add the repository and install the two halves yourself — see
    [installing on bare metal](../install/bare-metal.md). The package ships the
    configuration half as `librenms-webterm-setup`, so you can install the
    gateway however you like and run only that.

## 2. Enable the plugin in LibreNMS

Under **Overview → Plugins → Plugin Admin**, switch WebTerm on.

The installer sets WebTerm's own `enabled` setting, but LibreNMS keeps a separate
plugin row of its own and only an administrator in the web UI can flip it.

Nothing can open a shell yet — the plugin ships default-deny. That is the next
few steps.

## 3. Confirm both halves agree

```bash
# LibreNMS server, as the librenms user
./lnms webterm:doctor
```

`install.sh` already ran this and exited with its status, so if it finished
cleanly there is nothing to do here.

??? info "Doing the install by hand instead"

    Every step, written out — the reverse-proxy stanzas, the secret's group
    permissions, SELinux, and what to set where — is in
    [installing on bare metal](../install/bare-metal.md#doing-it-by-hand).
    The [reverse proxy](../operate/reverse-proxy.md) page explains which lines
    are load-bearing and what breaks without each one.

## 4. Add a credential and enable one device

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

## 5. Grant yourself access

```bash
# LibreNMS server, as the librenms user
./lnms webterm:ability grant --user=you --ability=use
./lnms webterm:grant --user=you --device=core-sw-01
```

Shell access is deliberately separate from — and narrower than — being able to *see* a device in LibreNMS. Being an admin is not sufficient.

## 6. Open a terminal

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
