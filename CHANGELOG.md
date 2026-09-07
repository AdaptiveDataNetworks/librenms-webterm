# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Plugin and gateway are released together and share a version number, but they are **installed separately** and support one protocol version of skew in each direction. Run `./lnms webterm:doctor` after upgrading either.

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

[1.0.1]: https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/tag/v1.0.1
[1.0.0]: https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/tag/v1.0.0
