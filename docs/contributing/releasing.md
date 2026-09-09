# Releasing

## Before tagging

One command, and it **fails** rather than printing:

```bash
sh tools/preflight.sh
```

It runs composer validation, the dependency guard, the docs check, Pint,
PHPStan, the test suite, the protocol codegen check, gofmt, go vet, go test,
and a real GoReleaser snapshot build.

!!! warning "Why this is a script and not a checklist"

    Two releases were tagged while static analysis was failing. The checks had
    been run individually and piped through `grep`, which hides the exit code,
    and the output scrolled past unread. A tag cannot be withdrawn once
    Packagist has seen it, so the gate has to fail rather than report.

    The GoReleaser snapshot is in there for the same reason: the release
    workflow only runs on a tag, so CI never exercises it. v1.0.0's release
    failed for a path error that this snapshot would have caught.

### The installer, on real distributions

`preflight.sh` checks the installer's logic but runs nothing. Before a release
that touches `packaging/`, run it against real systems:

```bash
tools/testbox/run.sh
```

This builds a container per distribution with real systemd, real nginx and real
Apache, installs the packages just built, runs the setup helper and asserts the
outcome — including a real WebSocket upgrade through the proxy returning 101,
and a foreign origin getting 403. Rocky 9, Alma 9, Debian 12 and Ubuntu 24.04
against both web servers.

Narrow it while iterating, and keep the container to poke at:

```bash
KEEP=1 tools/testbox/run.sh debian apache
```

It needs podman (rootless is fine) and goreleaser. Images are cached, so only
the first run is slow. It rebuilds the packages every time — reusing `dist/`
silently tests the previous commit.

What it does **not** cover is the plugin half: that needs a database and a full
Laravel app, and the nightly integration job already exercises it against real
LibreNMS core. SELinux in enforcing mode needs a VM, not a container.

Update `CHANGELOG.md`. Confirm the compatibility matrix in `docs/reference/compatibility.md` still reflects reality.

## Tagging

```bash
git tag -a v1.2.3 -m "Release v1.2.3"
git push origin v1.2.3
```

!!! warning "Stable versions on Packagist are immutable"

    A published stable version cannot be re-tagged. A bad `v1.2.3` is superseded by `v1.2.4`, never replaced. Do not move tags, and never force-push one.

The release workflow builds the gateway for amd64 and arm64, publishes deb/rpm/tarball/container artifacts, attaches build provenance, and deploys the versioned documentation.

## After tagging

Run the checker first — it covers most of this list and does not get the
comparison wrong:

```bash
php tools/verify-release.php v1.2.3
```

It verifies that the git tag, Packagist's published reference, the GitHub
release assets and the provenance attestation all point at the **same commit**.

!!! warning "Why this check exists"

    On 1.0.0 they did not agree. Packagist had already published the first tag
    when a broken release workflow was corrected and the tag re-cut; stable
    versions are immutable, so Packagist kept the original commit. The manual
    check that should have caught it compared against `1.0.0` while Packagist
    keys the version `v1.0.0`, and so reported success.

    If this fails on a mismatch, **do not re-tag** — it cannot help. Supersede
    with the next patch release.

Then confirm what the script does not cover:

- [ ] `ghcr.io/adaptivedatanetworks/librenms-webterm-gw:1.2.3` pulls
- [ ] The docs site shows the new version and `mike` has moved the `latest` alias

## Protocol changes

If `protocol/protocol.json` changed:

- [ ] `PROTOCOL.md` updated in the same commit
- [ ] Both generated files regenerated and committed
- [ ] N/N−1 skew still works in both directions
- [ ] The release notes say so prominently, because plugin and gateway upgrade independently

## Versioning

Semantic versioning. For this project specifically:

- **Major** — a protocol change that breaks N−1 skew, or a migration that cannot be rolled back.
- **Minor** — new features, new configuration, new protocol fields that older peers can ignore.
- **Patch** — fixes only.

A change that tightens a security default is a **minor** at least, never a patch: operators must be able to read about it before it lands.
