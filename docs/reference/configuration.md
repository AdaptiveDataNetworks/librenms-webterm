# Configuration reference

Two places: `config/webterm.php` in LibreNMS (with `.env` overrides) for the plugin, and environment variables for the gateway.

Runtime overrides set with `webterm:config set` take precedence over the config file.

## Plugin

### Global

| Key | Env | Default | Notes |
|---|---|---|---|
| `enabled` | `WEBTERM_ENABLED` | `false` | The global kill switch |

### Gateway connection

| Key | Env | Default |
|---|---|---|
| `gateway.url` | `WEBTERM_GATEWAY_URL` | `http://127.0.0.1:8377` |
| `gateway.ws_url` | `WEBTERM_GATEWAY_WS_URL` | `/webterm/ws` |
| `gateway.secret_file` | `WEBTERM_GATEWAY_SECRET_FILE` | `/etc/librenms-webterm/gateway.secret` |
| `gateway.connect_timeout_ms` | — | `2000` |
| `gateway.timeout_ms` | — | `5000` |

The timeouts are hard caps. A blackholed gateway must never consume every php-fpm worker, or "the terminal is down" becomes "LibreNMS is down".

### Credentials

| Key | Env | Default |
|---|---|---|
| `credentials.driver` | `WEBTERM_CREDENTIAL_DRIVER` | `database` |
| `credentials.database.key` | `WEBTERM_CREDENTIAL_KEY` | derived from `APP_KEY` |

Vault settings are in the [Vault reference](../vault/reference.md).

### Sessions

| Key | Default | Notes |
|---|---|---|
| `session.idle_timeout` | `900` | Seconds with no data in either direction |
| `session.max_duration` | `14400` | Absolute cap |
| `session.max_concurrent_per_user` | `3` | Grants can tighten this, never loosen it |
| `session.reconcile_interval` | `15` | Seconds between re-authorisation passes |

### Security

| Key | Env | Default |
|---|---|---|
| `security.step_up` | `WEBTERM_STEP_UP` | `true` |
| `security.step_up_grace_seconds` | — | `900` |
| `security.step_up_absolute_cap_seconds` | — | `28800` |
| `security.allowed_origins` | `WEBTERM_ALLOWED_ORIGINS` | *(empty — denies all)* |
| `security.host_key_policy` | `WEBTERM_HOST_KEY_POLICY` | `pin` |

### Audit

| Key | Env | Default |
|---|---|---|
| `audit.syslog` | `WEBTERM_AUDIT_SYSLOG` | `true` |
| `audit.json_file` | `WEBTERM_AUDIT_JSON_FILE` | *(none)* |
| `audit.mirror_to_eventlog` | — | `true` |
| `audit.retention_days` | — | `400` |

## Gateway

See [configuring the gateway](../gateway/configure.md).

## Defaults are closed

A fresh install cannot open a terminal to anything. That is intentional, and it means `webterm:doctor` on a new install reports several failures — it is a to-do list, not a fault.

| | Default |
|---|---|
| Plugin | Disabled |
| Allowed origins | None, so every connection is refused |
| Targets | None enabled |
| Grants | None |
| Host key policy | Pin required |
| Step-up | Required |
