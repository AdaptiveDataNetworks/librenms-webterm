# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Plugin scaffold: service provider with device-overview, settings and single-page hooks, registered through Laravel package auto-discovery.
- Wire protocol v1, specified in `protocol/PROTOCOL.md` and generated into both implementations from `protocol/protocol.json`.
- `Guard` — the single chokepoint ensuring no hook can throw and trigger LibreNMS's plugin auto-disable.
- Closed-by-default configuration: plugin disabled, no allow-listed origins, host-key pinning required, step-up authentication on.
- CI: dependency guard, protocol drift check, PHP matrix, LibreNMS integration install, docs build.
- Documentation: quickstart, security guide, LibreNMS update behaviour, Vault overview, architecture, compatibility, style guide.

[Unreleased]: https://github.com/adn/librenms-webterm/compare/main...HEAD
