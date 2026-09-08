# WebTerm and LibreNMS updates

**Read this before your next LibreNMS update.** It is the single most common source of confusion with any LibreNMS plugin, and it is entirely avoidable once you know what the updater does.

## What happens during a LibreNMS update

LibreNMS's `daily.sh` does something surprising: it **discards local changes to `composer.json` and `composer.lock`** —

```bash
git checkout --quiet -- composer.json composer.lock
```

— and then re-adds your plugins from a separate manifest, `composer.plugins.json`:

```bash
PLUGINS=$(call_daily_php "composer_get_plugins")
FORCE=1 ${COMPOSER} require --update-no-dev --no-install $PLUGINS
```

This is why `lnms plugin:add` performs **two** operations: a real install into `composer.json`, and a registration in `composer.plugins.json` so the updater can put your plugin back afterwards.

The practical consequences:

- **Never hand-edit `composer.json` to add WebTerm.** Your edit will be erased on the next update. Always use `lnms plugin:add`.
- Every LibreNMS update **re-resolves** WebTerm's version constraint. If you tracked `^1.0`, an update can move you to a newer 1.x.
- Your gateway binary is **not** touched by this. Plugin and gateway drift apart over time — see [version skew](#version-skew) below.
- `daily.sh` also runs `./lnms migrate`. WebTerm applies its own migrations when that finishes, so plugin schema stays current on every update without any manual step — see [validate.php warnings](validate-warnings.md#extra-migrations) for why it does not simply share core's migration table.
- After the update, the LibreNMS working tree legitimately has `composer.json` and `composer.lock` modified. The web UI reports that as a warning even though core meant to suppress it; that is an upstream bug, and it is [explained here](validate-warnings.md#modified-files-composerjson-and-composerlock).

## Pinning an exact version

If you need reproducible upgrades, register an exact version:

```bash
# LibreNMS server, as the librenms user
./lnms plugin:add adaptivedatanetworks/librenms-webterm 1.2.3
```

Verify what is registered:

```bash
# LibreNMS server, as the librenms user
cat /opt/librenms/composer.plugins.json
```

## Version skew

The plugin auto-updates with LibreNMS; the gateway does not. This means **mismatched versions are the normal state**, not an edge case.

WebTerm supports the current and previous protocol version (N and N−1). Within that window you get a warning banner and everything keeps working. Outside it, the terminal button disables itself rather than failing at connect time.

Check both versions at any time:

```bash
# LibreNMS server, as the librenms user
./lnms webterm:doctor
```

After a LibreNMS update, if the banner appears, upgrade the gateway to match.

## Common failures

??? failure "Your requirements could not be resolved to an installable set of packages"

    A dependency conflict between WebTerm and LibreNMS's own locked packages.

    This should not happen: WebTerm deliberately requires **only** `php` and `librenms/plugin-interfaces`, and CI resolves it against LibreNMS `master` nightly precisely to catch this before you do. If you see it, please [open an issue](https://github.com/AdaptiveDataNetworks/librenms-webterm/issues) with the full output — it is a bug on our side.

??? failure "Running composer update is not advisable. Please run composer install to update instead."

    LibreNMS blocks bare `composer update` to protect its lockfile. You reached this by running composer by hand. Use `./lnms plugin:add` instead, which sets the flag LibreNMS expects.

??? failure "Error: artisan must not run as root."

    LibreNMS refuses to run `artisan` as root. Switch user first:

    ```bash
    su - librenms
    ```

    If you have already run commands as root, fix the ownership they left behind:

    ```bash
    # LibreNMS server, as root
    chown -R librenms:librenms /opt/librenms/vendor /opt/librenms/bootstrap/cache
    ```

## Removing WebTerm

```bash
# LibreNMS server, as the librenms user
./lnms plugin:remove adaptivedatanetworks/librenms-webterm
```

This deregisters the plugin so `daily.sh` stops reinstating it. Stop and remove the gateway separately — it is a system service and is not managed by Composer.
