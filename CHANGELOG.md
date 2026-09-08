# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Plugin and gateway are released together and share a version number, but they are **installed separately** and support one protocol version of skew in each direction. Run `./lnms webterm:doctor` after upgrading either.

## [1.0.9] - 2026-09-08

Credentials were write-only. You could store one and then had no way to ask
what was stored, no way to remove it, and no warning when it disagreed with the
login the device actually uses.

### Added

- **`webterm:credentials:list`** — what is stored, for which device, under which
  login, and whether it still decrypts with the current key. No secret is
  printed. It also surfaces two failures that were previously silent: a
  credential whose username disagrees with the target's `principal` (the
  principal is what the SSH session uses; the username was only ever a label,
  and nothing checked they matched), and a row left on a superseded encryption
  key.

- **`webterm:credentials:forget`** — there was no way to delete a stored secret.
  `credentials:set` could only overwrite it, so a password written against the
  wrong device, or left behind after a move to Vault, could be removed only by
  editing the database by hand. It accepts a numeric device id even when the
  device is gone from LibreNMS: nothing links `webterm_credentials` to core's
  `devices` table, so deleting a device orphans its credential, and that row
  still has to be removable.

- Storing and deleting a credential are now audited (`credential.stored`,
  `credential.removed`) and classed security-relevant, so they reach the
  off-box stream before the local database write. Storing a device credential
  previously wrote no audit record at all.

### Fixed

- **`webterm:why` recommended a command that does not exist.** An operator
  blocked by a deny rule was told to run `./lnms webterm:deny --list`; there has
  never been a `webterm:deny`. It now names the real command. A test asserts
  every remediation string refers to a registered command, so this cannot
  return.

- `webterm:credentials:set` now warns when the login it is storing differs from
  the target's principal, and prints the command to fix whichever side is
  wrong.

## [Unreleased]

### Added

- **Credentials have a scope: global, device group, or device.** One secret can
  now serve a fleet. Previously the schema was strictly per-device
  (`device_id` NOT NULL, `unique(device_id, protocol)`), so an estate on one
  service account needed a row -- and a command -- per device.

  Most specific wins: device, then group, then global. A device in several
  groups resolves to the lowest-numbered group's credential, so the result never
  depends on row order. The order is fixed, not configurable: configurable
  precedence produces an operator who cannot predict which secret a device uses.

- **`webterm:credentials:explain --device=`** ships with it rather than after
  it. Scope buys one command instead of two hundred and costs the ability to
  know what any given device will do; that trade is only acceptable if the
  answer is one command away. It prints every candidate in precedence order,
  marks the winner, and names what it overrode.

- **An admin console in LibreNMS**, at `/plugin/webterm/admin`, covering targets,
  access (grants and abilities), host keys, sessions and audit.

  It deliberately does not set credentials. Storing a device secret requires
  shell access to the LibreNMS host and will continue to: LibreNMS is a
  public-facing PHP application whose compromise is the largest residual risk in
  this design, so the bar for writing a reusable device credential stays higher
  than an admin session in a browser. The console shows which credentials exist
  and what they apply to, which is not a secret.

  It also does not change host key pins or policy. Resetting a pin and switching
  a target to trust-on-first-connect are each defensible alone and together
  amount to turning off SSH host key verification from a browser.

  Access is WebTerm's own `admin` ability, never a core Gate ability — LibreNMS
  registers a `Gate::before` returning true for every ability when the user has
  the admin role, which would hand the console to every LibreNMS admin.
  Unauthorised requests get 404 rather than 403, so the console's existence is
  not confirmed to an account that may not use it.

  Authorization is re-checked on every request rather than inferred from route
  registration. `lnms plugin:enable` runs `route:cache`; `lnms plugin:disable`
  updates a column and nothing else, so a cached route table keeps serving these
  paths after a disable — including the automatic disable LibreNMS performs when
  a hook throws. An operator disabling the plugin to contain an incident must
  not be left with a live grant-writing surface.

### Changed

- `docs/security/threat-model.md` now states what the console costs. The
  "stolen session cookie" and "XSS in LibreNMS" sections previously rested on
  step-up bounding the damage; step-up gates opening a terminal, not
  administering the plugin, so for an account holding `admin` the worst case is
  now a persistent self-grant rather than one session.

- The plugin settings page no longer claims WebTerm cannot be configured from a
  page. It links to the console, and explains why credentials are still not
  settable there.

- `webterm:credentials:set` and `:forget` take `--global` and `--group=` as well
  as `--device=`. Exactly one is required — there is no default, because
  defaulting either way silently does the wrong thing.

- **`webterm:migrate --rollback --step=N`** undoes only the most recent
  migrations instead of every one. Without it, downgrading past a migration was
  a one-way door: the only rollback dropped every `webterm_*` table, audit
  history included.

- **`webterm:doctor` detects a schema newer than the code.** Downgrading past a
  migration leaves the columns changed and nothing pending, so every check that
  looks for outstanding work reports green while credential resolution dies on a
  missing column. Reproduced on MariaDB 11: `ERROR 1054 Unknown column
  'device_id'` on every credential path, with a clean bill of health from
  doctor. It now fails, and names the rollback command.

### Fixed

- `docs/contributing/development.md` told developers to install with
  `plugin:add ... @dev`, which resolves to the newest *release*, not the branch.
  Anyone following it silently got a released version. The constraint is
  `dev-main`.

### Notes

- The global scope stores `scope_ref = 0` rather than NULL. A unique index
  treats NULLs as distinct on MySQL, MariaDB and SQLite alike, so a nullable
  reference would have accepted two global credentials and left resolution to
  insertion order. A test pins this.

- The migration is idempotent by necessity, not habit. It is the first here to
  alter an existing table, and Laravel wraps a migration in a transaction only
  where the grammar supports schema transactions — true for PostgreSQL and SQL
  Server, false for every database this plugin supports. An interrupted run is
  never recorded, so the next run restarts from the top and must not fail on
  work it already did.

### Fixed

- **The documented way to upgrade the gateway could not work.** `upgrading.md`
  said `apt install --only-upgrade librenms-webterm-gw    # or dnf upgrade`,
  which presupposes a package repository. There isn't one -- releases are
  GitHub assets -- so those commands never find a newer gateway. The page now
  says so plainly, shows how to tell a packaged install from a tarball one
  (they do not mix; `install.sh` refuses to run over a package), and gives the
  real commands for each: `dnf install ./<file>.rpm`, `apt install ./<file>.deb`,
  or re-running the new release's `install.sh`. Checksum and provenance
  verification included.

- **`install.sh` recited a first-install checklist when upgrading.** Re-running
  it to upgrade printed "Before starting it: 1. Set your LibreNMS origin...
  2. Point the plugin at the secret..." -- steps the operator completed on the
  original install -- which reads like the script has just reset the
  configuration it in fact left alone. It now detects an existing binary and
  says what it replaced, what it preserved, and that the running gateway is
  still the old one until restarted. The pre-flight summary distinguishes the
  two cases too.

## [1.0.8] - 2026-09-08

Four defects that between them made a first install impossible to use. All were
found by auditing the paths a new operator walks, not by a bug report.

### Fixed

- **Nobody could open a terminal, on any install.** `ShellAuthorizer` fell back
  to `AlwaysChallengeStepUp` -- a placeholder whose `isSatisfied()` returns
  `false` unconditionally -- because nothing ever bound `StepUpGate`. Step-up
  is required by default, so every session mint denied with `StepUpRequired`
  and no amount of correct configuration helped. `TotpStepUp`, the real
  implementation, had been written but never wired in. The provider now binds
  it.

  Every existing step-up test injected a gate explicitly, so the gate you get
  when you inject nothing -- the one every real install uses -- was the single
  untested path. There is now a test for it.

- **The documented nginx config sent the WebSocket to a 404.** Both the
  quickstart and the reverse-proxy page used
  `proxy_pass http://127.0.0.1:8377;` with no URI component, which makes nginx
  forward the original `/webterm/ws`; the gateway serves `/ws`. The Apache
  block in the same page had it right, as did the nginx block for `/ui/`.

- **The quickstart never proxied the terminal's assets at all.** It documented
  only `/webterm/ws`, with no `/webterm/ui/` location, so the terminal page
  loaded and then rendered LibreNMS's 404 page where the terminal should be.

- **`webterm:doctor` gave a remediation that could not work.** The origin
  allow-list belongs to the gateway (`WEBTERM_ALLOWED_ORIGINS` in its
  environment file), but doctor read `webterm.security.allowed_origins` from
  the plugin's config -- a key nothing else consumes -- and told operators to
  set it with `webterm:config`. Following that advice produced a doctor that
  passed and a gateway that refused every browser connection with 403. Doctor
  now asks the gateway, which reports the count in `/api/v1/hello`.

### Added

- `webterm:doctor` reports step-up state, because the failure is otherwise
  invisible: the button appears, the click authorizes all the way to the last
  gate, and the denial says nothing about TOTP enrolment.

### Compatibility

The gateway gained one field in its `hello` response. A 1.0.7 gateway works
with a 1.0.8 plugin -- doctor says it cannot read the origin count and points
at the environment file instead. Upgrade both to get the check.

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

[1.0.9]: https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/tag/v1.0.9
[1.0.8]: https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/tag/v1.0.8
[1.0.7]: https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/tag/v1.0.7
[1.0.6]: https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/tag/v1.0.6
[1.0.5]: https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/tag/v1.0.5
[1.0.4]: https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/tag/v1.0.4
[1.0.3]: https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/tag/v1.0.3
[1.0.2]: https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/tag/v1.0.2
[1.0.1]: https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/tag/v1.0.1
[1.0.0]: https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/tag/v1.0.0
