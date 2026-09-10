# Upgrading

Plugin and gateway version **independently**, and that is the normal state, not an edge case: LibreNMS re-resolves the plugin on every update while the gateway binary is untouched.

## Version skew

| Skew | Behaviour |
|---|---|
| Same version | Normal |
| Any protocol mismatch | Refused outright |

The terminal tab checks the gateway's advertised protocol range before it offers
to connect, so a mismatch shows as a refusal with both versions named rather than
a failure after the operator has clicked and waited.

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

!!! tip "The package repository upgrades the gateway for you"

    If you added [the package repository](package-repo.md), `apt upgrade` and
    `dnf upgrade` pick up new gateway releases on their own and the rest of this
    section does not apply.

    The instructions below are for installs that fetch release assets directly.

First, check how the gateway was installed. The two paths do not mix: a tarball
install writes files your package manager does not know about, and letting
`install.sh` overwrite a package-managed binary would leave the two disagreeing
about what is on disk. It detects a packaged gateway and leaves the binary
alone — but the upgrade itself still belongs to your package manager.

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

    Re-running the installer upgrades an existing install. On a host that can
    reach the package repository it will move you onto packages, which is the
    better end state — after that, `apt upgrade` and `dnf upgrade` do this for
    you. Either way it leaves `gateway.env` and `gateway.secret` untouched.

    ```bash
    # as root
    BASE=https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/download/vX.Y.Z
    curl -fsSLO $BASE/install.sh
    sh install.sh
    ```

    The published `install.sh` is self-contained — the proxy and repository
    logic are compiled into it, so there is nothing else to fetch. It
    restarts the gateway itself and reports whether it came back up, so there
    is no separate `systemctl restart` to run.

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

    `webterm:doctor` compares the two and reports the mismatch. So does the
    terminal tab: the reconciler records the gateway's advertised range on each
    pass, and the tab refuses with that explanation rather than letting the
    click fail. A gateway that has never been heard from is treated as fine —
    an unchecked gateway must not hide the terminal on a fresh install.

## Order

Upgrade **both**, close together. Neither order is safe to leave half-finished:
the two must agree on the protocol version exactly, so whichever you upgrade
first, the terminal is refused until the other follows.

## After any upgrade

```bash
./lnms webterm:doctor
```
