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

## Tracking a development build

Packagist exposes every branch as a dev version, so `dev-main` is the current
head of `main` — unreleased work included. There is no separate branch to set up.

```bash
# LibreNMS server, as the librenms user
./lnms plugin:add adaptivedatanetworks/librenms-webterm dev-main
./lnms route:clear
./lnms webterm:migrate
./lnms webterm:doctor
```

!!! note "`webterm:migrate`, not `migrate`"

    LibreNMS resolves to Laravel's `production` environment, so `./lnms migrate`
    asks "Application In Production" and waits — which stalls any scripted or
    non-interactive run, and also applies core's migrations, not just this
    plugin's. `webterm:migrate` applies only ours and does not prompt.

This works even though LibreNMS sets `"minimum-stability": "stable"`: an
explicit dev constraint carries its own per-package stability flag, so nothing
in LibreNMS's own `composer.json` has to change. Switching between constraints
replaces the entry cleanly — there is no need to `plugin:remove` first.

!!! warning "Your install then follows `main` on every update"

    `daily.sh` discards `composer.json` and `composer.lock` on every update and
    re-resolves from `composer.plugins.json`, which now records `dev-main`. So
    the install picks up whatever has since been committed, without review. Use
    this on a test box, not on anything you depend on.

### Going back to a release

**Roll back any migrations the development build added _before_ downgrading the
package.** Laravel will not roll back a migration whose file is no longer on
disk, so once Composer has removed the newer code the schema cannot be reversed
with the shipped tooling.

```bash
# LibreNMS server, as the librenms user

# 1. what does the database have that a release does not?
./lnms webterm:migrate --status

# 2. undo them -- N is how many the dev build added
./lnms webterm:migrate --rollback --step=N

# 3. only now change the constraint back
./lnms plugin:add adaptivedatanetworks/librenms-webterm ^1.0
php artisan route:clear
./lnms webterm:doctor
```

Rolling a migration back is lossy by design: anything held only in the columns
it added is gone. Take a database dump first.

If you downgrade without step 2, nothing announces it. The released code queries
columns the newer schema has changed and every session dies at credential
resolution with an unknown-column error, while checks that look for *pending*
migrations see none. `webterm:doctor` detects this case specifically and reports
the schema as newer than the code — it is the one thing that will tell you.

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
