# Audit

Every WebTerm event is written to three places, on purpose.

## Where records go

**Off-box first, for security events.** Denials, step-up failures, host key changes and revocations are written to syslog (or a JSON file) *before* the database. An attacker who compromises LibreNMS can rewrite our table; they cannot recall a datagram that has already left the host. That ordering is the tamper-evidence story.

**The `webterm_audit` table**, which the admin UI reads. Append-only, enforced in code: attempting to update a record throws. Corrections are appended, which is also what makes them visible.

**LibreNMS's own eventlog**, so "someone opened a shell here at 03:12" appears on the device page where an operator is already looking. The duplication is the point.

!!! note "Why there is no hash chain"

    A tamper-evident hash chain sounds better than it works. It forks under concurrency, serialises the connect path on a single row lock, and breaks permanently the first time a retention purge removes an early record. The off-box stream is the control that actually survives an intruder, so that is the one we built.

## Configuration

```bash
# as the librenms user
./lnms webterm:config set audit.syslog true
./lnms webterm:config set audit.json_file /var/log/librenms/webterm-audit.json
./lnms webterm:config set audit.retention_days 400
```

Syslog goes to `LOG_AUTHPRIV`, which on most systems is already routed off-box and already treated as sensitive.

## What is recorded

| Event | When |
|---|---|
| `session.requested` | A session was minted |
| `session.denied` | Authorization refused, with the reason code |
| `session.started` / `session.ended` | The terminal opened and closed |
| `session.killed` / `session.revoked` | Ended by an administrator, or by losing access |
| `auth.stepup.failed` / `.satisfied` / `.locked` | Step-up outcomes |
| `credential.failed` | A credential could not be resolved |
| `hostkey.pinned` / `.changed` / `.rejected` | Trust decisions |
| `grant.created` / `.removed` | Access changes |
| `config.changed` | Settings changes, including who and what |

Event names are a **closed vocabulary**. They leave the host and people build alerts on them, so new situations get new names rather than reworded ones.

## Text is sanitised

Audit records carry attacker-influenced strings — hostnames, SSH banners, error messages from the far end. Log files are read in terminals, so escape sequences are stripped and newlines collapsed before anything is stored. Without that, a hostname containing a newline could forge an entire log line in your syslog stream.

## What is NOT recorded

**Session content.** WebTerm does not record keystrokes or terminal output. That is a deliberate omission, not an oversight. It means the audit trail tells you *that* someone opened a shell on a device, and when, but not what they did there. If you need the latter, your device's own logging or a dedicated session-recording product is the answer today.

**Credentials.** No credential is written to any sink at any level. There are tests asserting it on both the PHP and Go sides.

## Alerting

Worth alerting on, in rough order of urgency:

- `hostkey.changed` — either a legitimate change or an interception
- `auth.stepup.locked` — repeated failures against one account
- `session.denied` in volume from one user — probing, or a broken expectation
- `config.changed` where the key is `security.*` — someone relaxed a control
