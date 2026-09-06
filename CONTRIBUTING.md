# Contributing

Thanks for considering it. This is a security-sensitive project, so a few conventions matter more here than they might elsewhere.

## Before a large change

Open an issue first. A pull request that changes the trust boundaries — the three-phase handoff, the one-way rule, the authorization model, credential handling — needs a design discussion before code, and is otherwise likely to be turned down after you have done the work. That is a waste of your time we would rather avoid.

## Local setup

```bash
git clone https://github.com/AdaptiveDataNetworks/librenms-webterm.git
cd librenms-webterm
composer install
composer test
```

For the gateway:

```bash
cd gateway
go test ./...
```

To develop against a real LibreNMS install without publishing:

```bash
# in your LibreNMS directory, as the librenms user
composer config repositories.webterm '{"type":"path","url":"/path/to/librenms-webterm","options":{"symlink":true}}'
./lnms plugin:add adaptivedatanetworks/librenms-webterm @dev
```

## The rules that are not negotiable

**Never add a runtime Composer dependency.** `composer.json`'s `require` is `php` and `librenms/plugin-interfaces`, and `tools/composer-guard.php` fails CI if that changes. This is not fussiness: `lnms plugin:add` resolves our package against LibreNMS's own lockfile on every user's server, and a third dependency can make installation impossible for everyone and block their next LibreNMS update. Dev tooling goes in `require-dev`, which is never installed on user systems.

**Never let a hook throw.** LibreNMS catches any `Throwable` escaping a plugin hook, disables the plugin, and tells the user it broke. Route everything through `AdaptiveDataNetworks\WebTerm\Support\Guard::safely()`.

**Never edit generated files.** `src/Protocol.php` and `gateway/internal/proto/proto.go` come from `protocol/protocol.json`. Edit the JSON and run `php tools/generate-protocol.php`.

**Never touch LibreNMS core classes outside `src/Librenms/`.** That directory is the adapter layer, and every adapter has a contract test naming the symbol it depends on. LibreNMS moves; this is how we find out early instead of in a user's bug report.

**Protocol changes are documentation changes.** `protocol/PROTOCOL.md` is normative and updated in the same pull request.

## Tests

Bug fixes come with a regression test. Security-relevant behaviour comes with a test that would fail if the control were removed — the documentation makes claims of the form "X cannot happen", and every one of those must trace to a named test.

## Style

`composer fix` runs Pint. CI runs `composer test`, which is Pint plus PHPStan plus Pest. Documentation follows [the style guide](docs/contributing/style-guide.md).

## Commits and releases

Conventional commits (`feat:`, `fix:`, `docs:`). Maintainers tag releases; note that Packagist now makes stable versions immutable, so a bad tag is superseded rather than replaced.
