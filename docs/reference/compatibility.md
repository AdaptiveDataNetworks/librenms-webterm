# Compatibility

## LibreNMS

WebTerm requires the LibreNMS **v2 plugin system** — the Composer-package plugin architecture built on Laravel package auto-discovery, using `librenms/plugin-interfaces`.

!!! note "Minimum version is being established"

    The exact minimum LibreNMS release is being pinned against the changelog entry that stabilised `PluginManagerInterface::publishHook` and the five hook interfaces. Until that is confirmed, this table will not assert a number it cannot back up. CI resolves and installs WebTerm against LibreNMS `master` nightly.

## PHP

| | |
|---|---|
| Required | 8.2+ |
| Tested | 8.2, 8.3, 8.4 |

WebTerm tracks LibreNMS core's own PHP constraint. It deliberately does not require anything looser or tighter.

## Gateway platforms

| Platform | Status |
|---|---|
| linux/amd64 | Supported |
| linux/arm64 | Supported |
| darwin, freebsd, armv7 | Not built — we would be promising a platform we do not test |

## SSH algorithms

The gateway uses Go's `golang.org/x/crypto/ssh`, which does **not** implement some algorithms found on older equipment:

- `aes192-cbc`, `aes256-cbc`
- `hmac-md5`

No configuration profile can enable these — they are absent from the library, not disabled by policy. Devices offering only these cannot be reached through WebTerm and should continue to use your existing `ssh://` links or a console server.

This is a genuine functional gap relative to a native SSH client, and it lands on exactly the ageing equipment whose console access often matters most. We would rather state it here than have you discover it during a change window.

## Protocol version skew

The plugin and the gateway version independently, and LibreNMS's updater re-resolves the plugin without touching the gateway binary. Mismatch is the normal state.

| Skew | Behaviour |
|---|---|
| Same version | Normal operation |
| Any protocol mismatch | Refused. The plugin raises `GatewayVersionException` and the session is not created |
| Further apart | Terminal button disabled, with both versions named |

Check with `./lnms webterm:doctor`.
