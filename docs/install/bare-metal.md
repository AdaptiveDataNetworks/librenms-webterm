# Installing on a bare-metal LibreNMS

The classic `/opt/librenms` install with nginx or Apache and php-fpm.

## 1. The plugin

```bash
# LibreNMS server, as the librenms user
cd /opt/librenms
./lnms plugin:add adaptivedatanetworks/librenms-webterm
php artisan route:clear
```

Enable it under **Overview → Plugins → Plugin Admin**.

??? failure "Error: artisan must not run as root."

    LibreNMS refuses to run as root. Run `su - librenms` first. If you already ran commands as root, fix what they left behind:

    ```bash
    # as root
    chown -R librenms:librenms /opt/librenms/vendor /opt/librenms/bootstrap/cache
    ```

## 2. The gateway

Prefer a distribution package:

=== "Debian / Ubuntu"

    ```bash
    # as root
    curl -fsSLO https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/download/vX.Y.Z/librenms-webterm-gw_X.Y.Z_linux_amd64.deb
    apt install ./librenms-webterm-gw_X.Y.Z_linux_amd64.deb
    ```

=== "RHEL / Rocky / Alma"

    ```bash
    # as root
    curl -fsSLO https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/download/vX.Y.Z/librenms-webterm-gw_X.Y.Z_linux_amd64.rpm
    dnf install ./librenms-webterm-gw_X.Y.Z_linux_amd64.rpm
    ```

=== "Tarball"

    ```bash
    # as root
    curl -fsSLO https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/download/vX.Y.Z/install.sh
    less install.sh                       # read it first
    sh install.sh --version vX.Y.Z
    ```

The package creates a `librenms-webterm` system user, installs the binary and a systemd unit, and generates `/etc/librenms-webterm/gateway.secret` if none exists.

## 3. Share the secret with LibreNMS

Both processes read the same file. Give the web user read access through the group:

```bash
# as root
usermod -a -G librenms-webterm librenms
chown root:librenms-webterm /etc/librenms-webterm/gateway.secret
chmod 0640 /etc/librenms-webterm/gateway.secret
```

Then point the plugin at it:

```bash
# as the librenms user
./lnms webterm:config set gateway.secret_file /etc/librenms-webterm/gateway.secret
```

!!! note "php-fpm may need restarting"

    Adding a user to a group does not affect processes that are already running. Restart php-fpm so the web user picks up its new group.

## 4. Configure and start the gateway

Edit `/etc/librenms-webterm/gateway.env` and set your LibreNMS origin:

```bash
WEBTERM_ALLOWED_ORIGINS=https://librenms.example.com
```

Then:

```bash
# as root
systemctl enable --now librenms-webterm-gw
systemctl status librenms-webterm-gw
```

## 5. Reverse proxy

See [reverse proxy](../operate/reverse-proxy.md) for the nginx and Apache stanzas. Three settings there are load-bearing; skipping them produces symptoms that look like bugs elsewhere.

## 6. SELinux

On RHEL-family systems with SELinux enforcing, allow the web server to make the loopback connection:

```bash
# as root
setsebool -P httpd_can_network_connect on
```

## 7. Verify

```bash
# as the librenms user
./lnms webterm:doctor
```

Work through whatever it reports; every failure names its fix. Then continue with the [quickstart](../getting-started/quickstart.md) from step 5 to enable a device.
