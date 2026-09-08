# Command reference

All commands run from the LibreNMS directory as the `librenms` user:

```bash
su - librenms
cd /opt/librenms
```

## Diagnostics

```bash
./lnms webterm:doctor                 # check the installation
./lnms webterm:doctor --deep          # also verify host key pins
./lnms webterm:why --user=jsmith --device=core-sw-01
```

`webterm:why` runs the real authorization path and prints the command that fixes the failing step. It is the right first response to "why can't I open a terminal".

## Database

```bash
./lnms webterm:migrate                # apply pending plugin migrations
./lnms webterm:migrate --status       # what has run, and where it is recorded
./lnms webterm:migrate --pretend      # print the SQL without running it
./lnms webterm:migrate --rollback     # drop every webterm table (destructive)
```

The plugin records its migrations in `webterm_migrations` rather than in
LibreNMS's `migrations` table, so that `./validate.php` does not report them as
[extra migrations](../install/validate-warnings.md#extra-migrations). You do not
normally need to run this: the plugin applies its own migrations whenever a core
migration run finishes, which is what makes `./lnms migrate` — and therefore
`daily.sh` — keep the plugin current.

Run it by hand when upgrading from 1.0.6 or earlier, which moves the old rows
out of core's table, and when uninstalling.

## Configuration

```bash
./lnms webterm:config list
./lnms webterm:config get security.step_up
./lnms webterm:config set enabled true
./lnms webterm:config unset security.step_up
```

Changes are audited. Secrets cannot be set here, and anything whose key looks secret is masked on output.

## Targets

```bash
./lnms webterm:target:enable --device=core-sw-01 --principal=netops
./lnms webterm:target:enable --device=core-sw-01 --principal=netops --flow=ssh_signer
./lnms webterm:target:enable --device=old-switch --principal=admin --profile=legacy
./lnms webterm:target:enable --device=core-sw-01 --disable
```

| Option | Values |
|---|---|
| `--flow` | `database`, `ssh_signer`, `kv2`, `private_key` |
| `--policy` | `pin`, `tofu_first_connect` |
| `--profile` | `modern`, `legacy` |

## Access

```bash
./lnms webterm:ability list
./lnms webterm:ability grant --user=jsmith --ability=use
./lnms webterm:ability revoke --user=jsmith --ability=use

./lnms webterm:grant --user=jsmith --device=core-sw-01
./lnms webterm:grant --role=netops --group=4
./lnms webterm:grant --user=contractor --device=edge-01 --until="2026-09-30 18:00"
./lnms webterm:grant --user=jsmith --device=core-sw-01 --deny
./lnms webterm:grant --user=jsmith --device=core-sw-01 --remove
```

## Credentials

```bash
./lnms webterm:credentials:set --device=core-sw-01 --username=netops
./lnms webterm:credentials:set --device=core-sw-01 --username=netops --key-file=/path/to/key
./lnms webterm:credentials:rekey --dry-run
./lnms webterm:credentials:rekey --from="<old key>"
```

Secrets are always prompted for, never passed as arguments.

## Host keys

```bash
./lnms webterm:hostkey-scan
./lnms webterm:hostkey-scan --device=core-sw-01
./lnms webterm:hostkey-reset --device=core-sw-01 --reason="firmware upgrade CHG-1234"
```

## Sessions

```bash
./lnms webterm:sessions
./lnms webterm:sessions --kill=01J9Z8... --reason="access review"
./lnms webterm:reconcile
```

## Gateway

Run on the gateway host, not in LibreNMS:

```bash
librenms-webterm-gw version
librenms-webterm-gw init --path /etc/librenms-webterm/gateway.secret
librenms-webterm-gw healthcheck
librenms-webterm-gw serve
```
