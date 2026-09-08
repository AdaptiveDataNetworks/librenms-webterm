# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Plugin and gateway are released together and share a version number, but they are **installed separately** and support one protocol version of skew in each direction. Run `./lnms webterm:doctor` after upgrading either.

## [1.0.7] - 2026-09-08

### Fixed

- **`./validate.php` reported WebTerm's migrations as "extra migrations".**
  LibreNMS validates its schema by diffing the `migrations` table against the
  files in `database/migrations/` and nothing else, with no allow-list and no
  plugin awareness, so every row a plugin records there is flagged forever. The
  warning was cosmetic, but it sits in the same list as genuine schema
  corruption and an operator cannot tell the two apart.

  WebTerm now keeps its migrations in its own repository table,
  `webterm_migrations`, and writes nothing to core's. Because `./lnms migrate`
  can no longer discover them -- and LibreNMS's `daily.sh` runs exactly that on
  every update -- the plugin listens for the end of a core migration run and
  applies its own immediately afterwards. Both `MigrationsEnded` and
  `NoPendingMigrations` are handled: Laravel fires only the latter when core has
  nothing to migrate, which is the common case on a routine update.

  **Upgrading from 1.0.6 or earlier:** run `./lnms webterm:migrate` once to move
  the existing rows out of core's table. It moves bookkeeping only -- no
  migration is re-run and no schema changes. It also happens automatically the
  next time `./lnms migrate` runs.

- The nightly LibreNMS integration job had started failing before it installed
  anything: core now refuses to run `artisan` as any user but its configured
  one, and `composer install` reaches `artisan` through `post-autoload-dump`.

### Added

- `webterm:migrate`, with `--status`, `--pretend` and `--rollback`. Uninstall
  now uses `./lnms webterm:migrate --rollback`, which drops every `webterm_*`
  table including the migration repository, instead of core's
  `migrate:rollback --path=...`.
- `webterm:doctor` checks schema state, separating migrations that have not run
  -- a real fault -- from rows left in core's table, which is only cosmetic.
- A [validate.php warnings](https://adaptivedatanetworks.github.io/librenms-webterm/install/validate-warnings/)
  page documenting both warnings a Composer plugin can produce. The second one,
  `composer.json` and `composer.lock` showing as modified, is an upstream bug:
  core deliberately exempts exactly that case, but reads `composer.plugins.json`
  by a relative path, so the exemption only applies when the working directory
  is the LibreNMS root -- true for the CLI, false under php-fpm.

## [1.0.6] - 2026-09-07

### Fixed

- **Installing the plugin broke every LibreNMS device page.** The
  `DeviceOverviewHook` returned `[view, data]` -- the shape `MenuEntryHook`
  uses -- where LibreNMS's own `device/tabs/overview.blade.php` renders the
  result with `{{ $pluginView }}`. That threw
  `htmlspecialchars(): Argument #1 must be of type string, array given` inside
  core's template and produced "Whoops, looks like something went wrong" on
  every device, for every user, whether or not WebTerm was configured.

  `Support\Guard` could not catch it: the failure happens in LibreNMS's view
  after the hook has already returned. The hook now returns an `Htmlable`, and
  a regression test performs the same `e()` call core's template does.

  Present since 1.0.0. If you installed any earlier release, upgrade.

## [1.0.5] - 2026-09-07

Found during a real installation on PHP 8.5.

### Fixed

- Removed a `curl_close()` call. It has done nothing since PHP 8.0 and is
  deprecated from 8.5, so it printed a deprecation notice into command output
  and error logs on a current LibreNMS.

### Changed

- The test suite now fails on deprecations raised by this package. Two layers
  were hiding them: Testbench lowers `error_reporting` to mask `E_DEPRECATED`,
  and Laravel's exception handler routes deprecations to a log channel rather
  than raising them, so `failOnDeprecation` never saw one. A scoped handler
  now raises deprecations originating in `src/`, and vendor deprecations are
  deliberately left alone.
- CI tests PHP 8.5 in addition to 8.2-8.4. The deprecation above reached a user
  because the matrix stopped at 8.4 while LibreNMS runs on 8.5.
- `webterm:doctor` prints the plugin and PHP versions in its header.

## [1.0.4] - 2026-09-07

Found during a real first installation.

### Fixed

- **`webterm:doctor` gave dangerous advice when the shared secret existed but
  could not be read.** It reported the same failure for a missing file and an
  unreadable one, and suggested `librenms-webterm-gw init` -- which on a
  working install would replace a secret the gateway is already using and break
  every session mint. The two cases are now distinct, and the unreadable case
  explicitly says not to regenerate, naming the actual user, path and commands.

### Changed

- `install.sh` and the deb/rpm postinstall now add the LibreNMS account to the
  `librenms-webterm` group themselves, instead of telling the operator to
  "give LibreNMS read access" without saying how. `install.sh` takes
  `--librenms-user` for installations that run under a different account, and
  says plainly that php-fpm must be restarted and a fresh shell started, since
  group membership does not reach running processes.

## [1.0.3] - 2026-09-07

### Fixed

- **`webterm:config set` had no effect on the running application.** Settings
  were written to `webterm_config` and applied to the CLI process, but nothing
  ever read the table back, so the next web request fell back to the config
  file. An administrator running `webterm:config set enabled true` would see
  the command succeed, and every route would still return 403 with the plugin
  reporting itself switched off. This blocked the documented setup at its first
  step.

  Runtime settings are now applied during boot, before anything consults them.
  Loading tolerates a database that is absent, unmigrated or unreachable, since
  it runs on every request -- including during `lnms plugin:add`, before the
  migrations exist.

- Stored settings are converted to the type the config declares. Previously a
  value would have arrived as a string, and the string `"false"` is truthy --
  so a kill switch set to `false` would have read as ON.

## [1.0.2] - 2026-09-07

Packaging fixes. **The plugin code is unchanged** from 1.0.0.

### Fixed

- `install.sh` is now a release asset. The documentation tells operators to
  download it, read it, then run it -- but it existed only inside the release
  tarballs, so the documented `curl` 404'd. Being inside the tarball also
  defeats the purpose: you cannot read an installer before downloading the
  thing it installs.
- The release tarball now contains `gateway.env.example`. It did not, and
  `install.sh` skipped copying it silently, leaving operators with a gateway
  that starts, refuses every browser connection because no origin is
  allow-listed, and provides no config file to edit. `install.sh` now fails
  loudly if the file is missing.

### Added

- Migrations are tested against MariaDB 10.6, MariaDB 11 and MySQL 8.0 in CI.
  The unit suite runs on SQLite, which cannot exercise the reason the schema is
  written as it is: on MariaDB below 10.10 the first non-nullable TIMESTAMP
  silently acquires `ON UPDATE CURRENT_TIMESTAMP`, which would rewrite ticket
  and audit rows. Verified that no column acquires it and that rollback is
  clean.
- `tools/verify-release.php` asserts every asset the documentation names is
  actually published.

## [1.0.1] - 2026-09-07

Release engineering only. **The plugin code is byte-identical to 1.0.0** --
`src/`, `config/`, `database/`, `routes/` and `resources/` are unchanged.

### Fixed

- The release workflow now builds successfully. GoReleaser ran with
  `workdir: gateway` while every path in its config was root-relative, and the
  build-provenance step pointed at the old `dist/` location. Neither could be
  caught by CI, which never runs the release workflow -- it only runs on a tag.
- Packagist's `1.0.0` records commit `46346ae`, which is not the commit the
  `v1.0.0` tag or the release binaries were built from. Packagist had already
  published the first tag; stable versions are immutable, so the corrected
  re-tag could not update it. 1.0.1 makes Packagist, the git tag and the
  attested artifacts reference one commit.

### Added

- `tools/verify-release.php`, which checks that git, Packagist, the GitHub
  release and the provenance attestation all agree on the same commit. The
  check that should have caught the above was itself wrong: it compared
  against `1.0.0` while Packagist keys the version `v1.0.0`, so it reported
  success on a mismatch.

## [1.0.0] - 2026-09-07

### Added

**In-browser SSH terminals** for LibreNMS devices, opened from the device page.

**Two credential drivers.**
- `database` — encrypted at rest with a key derived from `APP_KEY` (or a dedicated `WEBTERM_CREDENTIAL_KEY`), with per-row key ids and a resumable `webterm:credentials:rekey` so rotating `APP_KEY` does not destroy your credentials.
- `vault` — HashiCorp Vault, with the SSH secrets engine issuing 30-minute signed certificates (nothing reusable is stored anywhere) or KV v2 for devices that cannot use certificates. Vault Agent and AppRole authentication; Enterprise namespaces supported.

**Authorization that is narrower than LibreNMS's own.** Being an administrator grants no terminal access. Abilities plus allow/deny grants over users, roles, devices and static device groups, with deny always winning and limits intersecting rather than widening. A property test asserts shell access is always a strict subset of device visibility.

**TOTP step-up authentication** at connect time, reusing the enrolment LibreNMS already holds, with a rolling grace, an absolute cap and single-use enforcement per TOTP step. Deliberately independent of LibreNMS's login two-factor session flag.

**SSH host key pinning**, verified before any authentication method is offered. Trust-on-first-use is available but refused for reusable secrets.

**An audit trail** written off-box before the database for security-relevant events, mirrored into LibreNMS's own eventlog, append-only, with all text sanitised of terminal escape sequences.

**A Go gateway** shipped as a static binary, deb, rpm and distroless container, with build provenance attestation. It holds no database credentials, no Vault token and no LibreNMS session, and never calls back into LibreNMS.

**Ten CLI commands**, including `webterm:doctor` and `webterm:why`, which run the real code paths and print the command that fixes what they find.

**38 pages of documentation**, including a complete Vault worked example and an honest threat model.

### Security

Defaults are closed. A fresh install cannot open a terminal to anything until an administrator enables the plugin, allow-lists an origin, enables a target, pins a host key and issues a grant.

### Deliberately not included

Session recording, RDP/VNC, just-in-time access approvals, break-glass credentials and cryptographic operator attribution. Each is discussed in the documentation rather than left as an unexplained gap.

[1.0.7]: https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/tag/v1.0.7
[1.0.6]: https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/tag/v1.0.6
[1.0.5]: https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/tag/v1.0.5
[1.0.4]: https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/tag/v1.0.4
[1.0.3]: https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/tag/v1.0.3
[1.0.2]: https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/tag/v1.0.2
[1.0.1]: https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/tag/v1.0.1
[1.0.0]: https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/tag/v1.0.0
