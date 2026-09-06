# Upgrading

Plugin and gateway version **independently**, and that is the normal state, not an edge case: LibreNMS re-resolves the plugin on every update while the gateway binary is untouched.

## Version skew

| Skew | Behaviour |
|---|---|
| Same version | Normal |
| One version apart | Works, with a warning banner |
| Further apart | The terminal button disables itself |

The button disabling is deliberate. Failing at connect time — after the user has clicked, authenticated and waited — is a worse experience than not offering the button.

Check both halves at any time:

```bash
# as the librenms user
./lnms webterm:doctor
```

## Upgrading the plugin

```bash
# as the librenms user
./lnms plugin:add adn/librenms-webterm     # re-resolves to the newest matching release
php artisan route:clear
./lnms migrate                              # if the release adds migrations
```

To pin an exact version, see [LibreNMS updates](librenms-updates.md#pinning-an-exact-version).

## Upgrading the gateway

=== "Package"

    ```bash
    # as root
    apt install --only-upgrade librenms-webterm-gw    # or dnf upgrade
    systemctl restart librenms-webterm-gw
    ```

=== "Tarball"

    ```bash
    # as root
    sh install.sh --version vX.Y.Z
    systemctl restart librenms-webterm-gw
    ```

Restarting ends live sessions. Each one is told what happened before the socket closes, rather than simply dropping — but pick your moment anyway.

The upgrade never regenerates the shared secret. If it did, LibreNMS would keep the old one and every session mint would fail with an opaque 401.

## Order

Upgrade the **gateway first**, then the plugin. A newer gateway accepts an older plugin's protocol version; the reverse is not guaranteed.

## After any upgrade

```bash
./lnms webterm:doctor
```
