# Development

## Setup

```bash
git clone https://github.com/AdaptiveDataNetworks/librenms-webterm.git
cd librenms-webterm
composer install
composer test
```

The gateway:

```bash
cd gateway
go test ./...
go build ./cmd/librenms-webterm-gw
```

## Against a real LibreNMS

```bash
# in your LibreNMS directory, as the librenms user
composer config repositories.webterm \
  '{"type":"path","url":"/path/to/librenms-webterm","options":{"symlink":true}}'
./lnms plugin:add adaptivedatanetworks/librenms-webterm @dev
```

With `symlink: true`, edits are live.

## The rules that are not negotiable

**Never add a runtime Composer dependency.** `require` is `php` and `librenms/plugin-interfaces`, and `tools/composer-guard.php` fails CI if that changes. This is not fussiness: `lnms plugin:add` resolves our package against LibreNMS's own lockfile on every user's server, and a third dependency can make installation impossible for everyone and block their next LibreNMS update.

**Never let a hook throw.** LibreNMS catches any `Throwable` escaping a plugin hook, disables the plugin and tells the user it broke. Route everything through `Support\Guard::safely()`.

**Never edit generated files.** `src/Protocol.php` and `gateway/internal/proto/proto.go` come from `protocol/protocol.json`:

```bash
php tools/generate-protocol.php
```

**Never reference a LibreNMS class outside `src/Librenms/`.** That directory is the adapter layer, and every adapter declares the core symbols it depends on so the contract tests can verify them.

**Protocol changes are documentation changes.** `protocol/PROTOCOL.md` is normative and updated in the same pull request.

## Cross-implementation tests

The PHP client is exercised against the real Go binary. These are the only tests that prove the two halves agree:

```bash
cd gateway && go build -o /tmp/gw ./cmd/librenms-webterm-gw
WEBTERM_GATEWAY_BIN=/tmp/gw vendor/bin/pest --testsuite=Feature
```

CI fails if they skip.

## Contract tests

These verify that the LibreNMS symbols we depend on still exist. They skip without a checkout and run in the nightly integration job:

```bash
WEBTERM_LIBRENMS_PATH=/opt/librenms vendor/bin/pest --testsuite=Contract
```

## Docs

```bash
pip install -r docs/requirements.txt
mkdocs serve
```

Without Python to hand, the structural checks still run:

```bash
php tools/check-docs.php
```

## Before opening a pull request

```bash
composer fix          # Pint
composer test         # Pint, PHPStan, Pest
php tools/composer-guard.php
php tools/check-docs.php
cd gateway && gofmt -l . && go vet ./... && go test ./...
```
