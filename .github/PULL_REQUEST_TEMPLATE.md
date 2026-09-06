## What this changes

<!-- and why -->

## Checklist

- [ ] `composer test` passes (Pint, PHPStan, Pest)
- [ ] `cd gateway && gofmt -l . && go vet ./... && go test ./...` passes
- [ ] `php tools/composer-guard.php` passes — **no new runtime dependency**
- [ ] `php tools/check-docs.php` passes
- [ ] Documentation updated for any user-facing change
- [ ] `protocol/PROTOCOL.md` updated if `protocol.json` changed, and both
      generated files regenerated

## If this touches security

- [ ] The behaviour is covered by a test that would fail if the control were removed
- [ ] Any new default is closed rather than open
- [ ] No credential can reach a log, a URL, a cookie, the DOM or a stack trace
- [ ] The threat model still describes reality

<!--
A reminder of the rules that are not negotiable, from CONTRIBUTING.md:

  * Runtime `require` stays at php + librenms/plugin-interfaces. `lnms plugin:add`
    resolves against LibreNMS's own lockfile on every user's server.
  * No hook may throw; LibreNMS disables the plugin if one does.
  * No LibreNMS class is referenced outside src/Librenms/.
  * Generated files are not edited by hand.
-->
