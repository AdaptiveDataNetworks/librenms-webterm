# `./validate.php` warnings

LibreNMS's `./validate.php` is the first thing anyone runs when something looks
wrong, and a plugin that adds noise to it is a plugin nobody trusts. This page
covers the two warnings that installing a Composer plugin can produce, what
causes each one, and which of them this plugin can do anything about.

## Extra migrations

```text
[WARN]  Your database schema has extra migrations (2026_01_01_000001_create_webterm_config_table, ...).
        If you just switched to the stable release from the daily release, your database is in between
        releases and this will be resolved with the next release.
```

**Cause.** LibreNMS validates its schema by comparing the `migrations` table
against the files in one directory:

```php
// LibreNMS/DB/Schema.php
public static function getUnexpectedMigrations()
{
    return self::getAppliedMigrations()->diff(self::getMigrationFiles());
}

private static function getMigrationFiles()
{
    return collect(glob(base_path('database/migrations/') . '*.php'))
        ->map(fn ($f) => basename($f, '.php'));
}
```

There is no allow-list and no plugin awareness. Any row naming a migration core
does not itself ship is reported, permanently — so *every* LibreNMS plugin that
records migrations in the shared table produces this warning, by construction.

**What this plugin does about it.** It keeps its migrations in a repository
table of its own, `webterm_migrations`, and writes nothing to core's
`migrations` table. The warning does not appear.

The cost is that `./lnms migrate` no longer discovers the plugin's migrations,
which matters because LibreNMS's `daily.sh` runs exactly that on every update.
So the plugin listens for the end of a core migration run and applies its own
migrations immediately afterwards. In practice `./lnms migrate` behaves as it
always did.

!!! info "Upgrading from 1.0.6 or earlier"

    Those releases did record migrations in core's table, so the warning is
    present until the rows are moved:

    ```bash
    # as the librenms user
    ./lnms webterm:migrate
    ```

    This moves the bookkeeping rows and changes no schema — no migration is
    re-run, no table is touched. It is safe to run repeatedly, and it runs on
    its own the next time `./lnms migrate` does.

    Check what it will do first with `./lnms webterm:migrate --status`.

## Modified files: `composer.json` and `composer.lock`

```text
[WARN]  Your local git contains modified files, this could prevent automatic updates.
        Modified Files: composer.json, composer.lock
```

**Cause.** `./lnms plugin:add` runs `composer require`, which by design edits
both files in the LibreNMS working tree. LibreNMS then checks
`git diff --name-only --exit-code` and reports anything modified.

Core already anticipates this and deliberately exempts it:

```php
// LibreNMS/Validations/Updates.php
if (! ($cmdoutput === ['composer.json', 'composer.lock'] && ComposerHelper::getPlugins())) {
    // ... warn
}
```

**Why you may still see it.** The exemption depends on
`ComposerHelper::getPlugins()`, which reads its file by a **relative** path:

```php
$plugins = is_file('composer.plugins.json') ? ... : [];
```

That resolves only when the process's working directory is the LibreNMS root.
It is when you run `./validate.php` from a shell, so the CLI is quiet. It is not
under php-fpm, whose working directory is the document root — so the web UI's
**Validate** page reports the warning even though core intended to suppress it.

**What this plugin does about it.** Nothing, and nothing it could do: the check,
the exemption and the bug are all in LibreNMS core. The one-line upstream fix is
to make the path absolute:

```php
$plugins = is_file(base_path('composer.plugins.json')) ? ... : [];
```

**Is it safe to ignore?** Yes. The modification is real and expected — it is the
record of which plugins you installed, and `daily.sh` restores both files from
git and re-applies your plugins from `composer.plugins.json` on every update.
See [LibreNMS updates](librenms-updates.md) for what that sequence does.

To confirm nothing else is modified:

```bash
# as the librenms user, from the LibreNMS root
git diff --name-only
```

If that prints only `composer.json` and `composer.lock`, your install is in the
state LibreNMS expects.

## Checking the plugin's own view

`webterm:doctor` reports schema state independently of `./validate.php`, and
knows the difference between migrations that have not run — a real fault — and
bookkeeping rows in the wrong table, which is cosmetic:

```bash
# as the librenms user
./lnms webterm:doctor
```
