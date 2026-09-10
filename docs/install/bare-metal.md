# Installing on a bare-metal LibreNMS

The classic `/opt/librenms` install with nginx or Apache and php-fpm.

There are two paths. The setup helper does all of it and asks before each
decision; the manual steps below are the same work written out, for anyone who
would rather run it themselves or is automating with a configuration manager.

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

## 2. The gateway package

=== "Debian / Ubuntu"

    ```bash
    # LibreNMS server, as root
    install -d -m 0755 /usr/share/keyrings
    curl -fsSL https://packages.adaptivedatanetworks.com/adn-archive-keyring.asc \
      | gpg --dearmor -o /usr/share/keyrings/adn-archive-keyring.gpg

    cat > /etc/apt/sources.list.d/adn.sources <<'EOF'
    Types: deb
    URIs: https://packages.adaptivedatanetworks.com/deb
    Suites: stable
    Components: main
    Architectures: amd64 arm64
    Signed-By: /usr/share/keyrings/adn-archive-keyring.gpg
    EOF

    apt update && apt install librenms-webterm-gw
    ```

=== "RHEL / Rocky / Alma"

    ```bash
    # LibreNMS server, as root
    rpm --import https://packages.adaptivedatanetworks.com/adn-archive-keyring.asc

    cat > /etc/yum.repos.d/adn.repo <<'EOF'
    [adn]
    name=Adaptive Data Networks
    baseurl=https://packages.adaptivedatanetworks.com/rpm/
    enabled=1
    gpgcheck=1
    repo_gpgcheck=1
    gpgkey=https://packages.adaptivedatanetworks.com/adn-archive-keyring.asc
    EOF

    dnf install librenms-webterm-gw
    ```

Adding the repository means `apt upgrade` and `dnf upgrade` pick up future
gateway releases on their own. See [the package repository](package-repo.md)
for the key's fingerprint and how to remove the repository later.

??? info "One-off download instead"

    ```bash
    # as root, Debian / Ubuntu
    curl -fsSLO https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/download/vX.Y.Z/librenms-webterm-gw_X.Y.Z_linux_amd64.deb
    apt install ./librenms-webterm-gw_X.Y.Z_linux_amd64.deb
    ```

    ```bash
    # as root, RHEL / Rocky / Alma
    curl -fsSLO https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/download/vX.Y.Z/librenms-webterm-gw_X.Y.Z_linux_amd64.rpm
    dnf install ./librenms-webterm-gw_X.Y.Z_linux_amd64.rpm
    ```

The package installs the binary, a systemd unit, a `librenms-webterm` system
user and `/etc/librenms-webterm/`. It does **not** start the gateway: a gateway
started before its allowed origins are set refuses every browser connection
with a 403 and looks broken. That is the next step's job.

## 3. Run the setup helper

```bash
# as root
librenms-webterm-setup
```

It finds your LibreNMS directory and web server, asks for anything it cannot
safely infer, shows you the plan, and does nothing until you say yes.

Expect roughly this:

```text
== Looking around
  LibreNMS:    /opt/librenms
  runs as:     librenms
  web server:  nginx
  php-fpm:     php8.2-fpm

What URL do operators use to reach LibreNMS?
  origin: https://librenms.example.com

== Plan
  * leave the packaged gateway alone (librenms-webterm-gw 1.0.9)
  * set WEBTERM_ALLOWED_ORIGINS=https://librenms.example.com
  * install and migrate the plugin as librenms
  * offer to add the WebTerm proxy to nginx
  * verify with webterm:doctor

Proceed? [y/N]
```

### Why it asks for the origin

Because it cannot be derived, and guessing wrong produces a 403 on every
connection with nothing in the logs to explain it. `APP_URL` is unset on a
stock LibreNMS and reads back as `http://localhost`; `base_url` is legitimately
a bare path; and `server_name` knows nothing about a TLS terminator in front of
it. The browser sends the origin *it* used, so that is the one the gateway has
to be told about.

### What it does to your web server config

It writes `/etc/nginx/webterm-proxy.conf` (or the Apache equivalent) and adds a
single `include` inside your LibreNMS server block, behind a marker comment:

```nginx
server {
    # librenms-webterm (managed) -- remove this line to detach
    include /etc/nginx/webterm-proxy.conf;
    listen 443 ssl;
    ...
```

Your vhost is backed up first, your web server's own config test has to pass,
and anything short of that restores the original byte for byte. Re-running
finds the marker and stops. To detach, delete those two lines and reload.

If you have a `:80` block that only redirects to `:443`, the include goes in
the `:443` block — putting it in the redirect gives you an install that reports
success and then 404s.

Decline the offer and it prints the stanzas for you to add yourself.

### Unattended

Every prompt has a flag:

```bash
librenms-webterm-setup \
    --origin https://librenms.example.com \
    --librenms-dir /opt/librenms \
    --webserver nginx --vhost /etc/nginx/conf.d/librenms.conf \
    --configure-webserver --selinux -y
```

`--dry-run` prints the plan and exits. `--no-configure-webserver`,
`--no-install-plugin`, `--no-selinux` and `--no-install-gateway` each opt out of
one part. Run it with `--help` for the full list.

The exit code is `webterm:doctor`'s, so it is safe to gate a playbook on.

## 4. Verify

The helper finishes by proving the path end to end and then running
`webterm:doctor`. To repeat either by hand:

```bash
# as the librenms user
cd /opt/librenms && ./lnms webterm:doctor
```

Then continue with the [quickstart](../getting-started/quickstart.md) from step
5 to enable a device.

---

## Doing it by hand

Everything the helper does, as individual steps.

### Share the secret with LibreNMS

Both processes read the same file. Give the web user read access through the group:

```bash
# as root
usermod -a -G librenms-webterm librenms
chown root:librenms-webterm /etc/librenms-webterm/gateway.secret
chmod 0640 /etc/librenms-webterm/gateway.secret
```

`/etc/librenms-webterm/gateway.secret` is already the plugin's default, so
there is nothing to configure. Only if you moved it, add this to
`/opt/librenms/.env` — `webterm:config` refuses the key, because a config row
pointing somewhere the gateway is not reading fails every session mint with an
opaque 401:

```bash
WEBTERM_GATEWAY_SECRET_FILE=/path/to/gateway.secret
```

!!! note "php-fpm may need restarting"

    Adding a user to a group does not affect processes that are already running. Restart php-fpm so the web user picks up its new group.

### Configure and start the gateway

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

### Reverse proxy

See [reverse proxy](../operate/reverse-proxy.md) for the nginx and Apache
stanzas. Three settings there are load-bearing; skipping them produces symptoms
that look like bugs elsewhere.

### SELinux

On RHEL-family systems with SELinux enforcing, allow the web server to make the
loopback connection:

```bash
# as root
setsebool -P httpd_can_network_connect on
```

### Verify

```bash
# as the librenms user
./lnms webterm:doctor
```

Work through whatever it reports; every failure names its fix.

---

## Installing from the tarball

If you are not using packages, the same script ships in the release tarball
alongside `webserver.sh`, which it needs:

```bash
# as root
curl -fsSLO https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/download/vX.Y.Z/librenms-webterm-gw_X.Y.Z_linux_amd64.tar.gz
tar -xzf librenms-webterm-gw_X.Y.Z_linux_amd64.tar.gz
less packaging/install.sh                     # read it first
sh packaging/install.sh --version vX.Y.Z
```

Here it *does* install the gateway, so `--version` is required: an unpinned
install cannot be reproduced. It downloads the release, verifies the published
SHA-256 before unpacking, and refuses to continue if that fails.
