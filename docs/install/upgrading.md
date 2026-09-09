# Upgrading

Plugin and gateway version **independently**, and that is the normal state, not an edge case: LibreNMS re-resolves the plugin on every update while the gateway binary is untouched.

## Version skew

| Skew | Behaviour |
|---|---|
| Same version | Normal |
| Any protocol mismatch | Refused outright |

The button disabling is deliberate. Failing at connect time — after the user has clicked, authenticated and waited — is a worse experience than not offering the button.

Check both halves at any time:

```bash
# as the librenms user
./lnms webterm:doctor
```

## Upgrading the plugin

```bash
# as the librenms user
./lnms plugin:add adaptivedatanetworks/librenms-webterm     # re-resolves to the newest matching release
php artisan route:clear
./lnms migrate                              # applies plugin migrations too
```

`./lnms migrate` is enough: the plugin watches for the end of a core migration
run and applies its own migrations then, which is also how LibreNMS's `daily.sh`
keeps the plugin's schema current without anyone doing anything. `./lnms
webterm:migrate` does the same thing directly, and `./lnms webterm:doctor` will
tell you if anything is outstanding.

To pin an exact version, see [LibreNMS updates](librenms-updates.md#pinning-an-exact-version).

## Upgrading the gateway

!!! info "There is no APT or YUM repository"

    Releases are published as GitHub release assets, not through a package
    repository, so `apt upgrade` and `dnf upgrade` will never find a new
    gateway. Point your package manager at the downloaded file instead.

First, check how the gateway was installed — the two paths do not mix, and
`install.sh` refuses to run over a packaged install for exactly that reason:

```bash
# as root
rpm -q librenms-webterm-gw 2>/dev/null || dpkg -s librenms-webterm-gw 2>/dev/null || echo "not from a package -- tarball install"
```

=== "RPM (RHEL, Rocky, Alma, Fedora)"

    ```bash
    # as root
    V=X.Y.Z
    ARCH=$([ "$(uname -m)" = aarch64 ] && echo arm64 || echo amd64)
    BASE=https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/download/v$V

    curl -fsSLO $BASE/librenms-webterm-gw_${V}_linux_${ARCH}.rpm
    curl -fsSL $BASE/checksums.txt | grep " librenms-webterm-gw_${V}_linux_${ARCH}.rpm$" | sha256sum -c -

    dnf install ./librenms-webterm-gw_${V}_linux_${ARCH}.rpm
    systemctl restart librenms-webterm-gw
    ```

    `dnf install` on a file whose version is newer than the installed one
    performs an upgrade; there is no separate command. Your `gateway.env` and
    `gateway.secret` are config files and are left alone, and a gateway that
    was running is restarted onto the new binary.

=== "DEB (Debian, Ubuntu)"

    ```bash
    # as root
    V=X.Y.Z
    ARCH=$([ "$(uname -m)" = aarch64 ] && echo arm64 || echo amd64)
    BASE=https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/download/v$V

    curl -fsSLO $BASE/librenms-webterm-gw_${V}_linux_${ARCH}.deb
    curl -fsSL $BASE/checksums.txt | grep " librenms-webterm-gw_${V}_linux_${ARCH}.deb$" | sha256sum -c -

    apt install ./librenms-webterm-gw_${V}_linux_${ARCH}.deb
    systemctl restart librenms-webterm-gw
    ```

=== "Tarball (install.sh)"

    Fetch the installer belonging to the version you are moving to, then re-run
    it. It verifies the checksum itself, replaces the binary and the systemd
    unit, and leaves `gateway.env` and `gateway.secret` untouched.

    ```bash
    # as root
    curl -fsSLO https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/download/vX.Y.Z/install.sh
    sh install.sh --version vX.Y.Z
    systemctl restart librenms-webterm-gw
    ```

If you have the GitHub CLI, every release asset carries build provenance:

```bash
gh attestation verify ./librenms-webterm-gw_X.Y.Z_linux_amd64.rpm \
  --repo AdaptiveDataNetworks/librenms-webterm
```

Restarting ends live sessions. Each one is told what happened before the socket closes, rather than simply dropping — but pick your moment anyway.

The upgrade never regenerates the shared secret. If it did, LibreNMS would keep the old one and every session mint would fail with an opaque 401.

!!! warning "There is no skew tolerance today"

    The gateway advertises `min_protocol` and `max_protocol`, and both are
    currently **1** — so the plugin and gateway must agree exactly. A mismatch
    is refused when a session is created, which is *after* the operator has
    clicked, and it surfaces as an error rather than a disabled button.

    `webterm:doctor` compares the two and reports the mismatch before anyone
    clicks. Run it after upgrading either half.

## Order

Upgrade the **gateway first**, then the plugin. A newer gateway accepts an older plugin's protocol version; the reverse is not guaranteed.

## After any upgrade

```bash
./lnms webterm:doctor
```
