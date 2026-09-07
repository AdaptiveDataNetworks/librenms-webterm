# Releasing

## Before tagging

```bash
composer test
php tools/composer-guard.php
php tools/check-docs.php
cd gateway && go test ./... && gofmt -l . && cd ..

# The release workflow is NOT exercised by CI -- it only runs on a tag. Prove
# it builds before you create one:
goreleaser check
goreleaser release --snapshot --clean --skip=docker,sign
```

That snapshot produces the real archives, deb and rpm in `dist/`. Check that
the tarball contains `LICENSE`, and that the binary runs:

```bash
tar -tzf dist/librenms-webterm-gw_*_linux_amd64.tar.gz
```

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

- [ ] Packagist shows the new version (the webhook is near-instant; without it, crawling takes about a week)
- [ ] GitHub Releases lists all artifacts and `checksums.txt`
- [ ] `gh attestation verify <artifact> --repo adaptivedatanetworks/librenms-webterm` passes
- [ ] `ghcr.io/adaptivedatanetworks/librenms-webterm-gw:1.2.3` pulls
- [ ] The docs site shows the new version, **and it appears in `versions.json`** — the workflow checks this, but confirm
- [ ] `mike` has moved the `latest` alias

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
