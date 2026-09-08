# Threat model

What WebTerm defends against, what it does not, and why.

Read [Should you enable this?](index.md) first — this page assumes you have decided the feature is worth having.

## Assets

| Asset | Why it matters |
|---|---|
| Device credentials | Direct access to network equipment |
| Live terminal sessions | Direct access, already authenticated |
| The gateway shared secret | Lets the holder mint sessions |
| The audit trail | The record of what happened |

## Attacks and controls

### Stolen LibreNMS session cookie

**Impact:** the attacker acts as that operator, including opening terminals.

**Controls:** step-up authentication challenges at connect time and is tracked server-side, deliberately not in the session — so the stolen cookie does not carry step-up with it. Grants limit which devices. Every session is audited. Concurrency limits bound parallel abuse.

**Residual:** step-up has a grace window, so a cookie stolen during that window is sufficient. Shorten `step_up_grace_seconds` if that trade does not suit you.

**If the operator holds the `admin` ability, this is worse.** The admin console is reachable with the cookie alone — it is not behind step-up, because step-up gates opening a terminal, not administering the plugin. An attacker with an admin's cookie can therefore write themselves a grant, grant themselves the `use` ability, and enable a target. They still cannot read a stored credential (the console never renders one) and they still face step-up before a terminal opens, but they can arrange for their *own* account to be permitted afterwards. Every one of those writes is audited and classed security-relevant, so it reaches an off-box stream before the local table.

Grant the `admin` ability to as few accounts as you would trust with `sudo` on the LibreNMS host, and read `webterm:doctor` and the audit tab as the detection surface. If that trade is not acceptable, revoke the ability entirely and administer the plugin from the CLI, which is the only path that requires shell access.

### XSS in LibreNMS

**Impact:** script in the operator's session can drive the UI, including minting a session — and, if that operator holds the `admin` ability, driving the admin console. The worst case is therefore not one session: it is a persistent self-grant that outlives the script. CSRF tokens do not help here, because script running in the page can read them. The controls are the same as for a stolen cookie above: the `admin` ability on as few accounts as possible, and the audit trail as detection.

**Controls:** the ticket is single-use with a 30-second TTL; the terminal runs in a sandboxed iframe from a distinct path; credentials never reach the browser at all.

**Residual:** **this attack largely succeeds.** Script running in an authenticated session can do what the user can do. Every control here assumes the browser is not the adversary. Keeping LibreNMS patched is the actual defence.

### Compromise of the LibreNMS host

**Impact:** with Vault, the attacker can request certificates for any device the signer role permits. With the database driver, they can decrypt every stored credential.

**Controls:** `allowed_users` on the Vault role, 30-minute TTLs, per-group signer roles, Vault's independent audit device, a credential key derived separately from `APP_KEY`.

**Residual:** **not preventable.** PHP-FPM must be able to reach the signing endpoint; anything with code execution there can too. This is inherent to any design where the NMS connects on your behalf, and it is stated on the first page an enterprise reads.

### Compromise of the gateway host

**Impact:** access to live sessions and to credentials in flight.

**Controls:** the gateway holds no database credentials, no Vault token and no LibreNMS session; it never writes to disk; it runs unprivileged under a systemd sandbox with an empty capability set and a syscall filter.

**Residual:** live sessions are exposed while they run. Co-locating gateway and LibreNMS means one compromise is both — see [requirements](../install/requirements.md).

### Hostile or substituted device

**Impact:** a device that is not what you think it is collects the credential you offer it.

**Controls:** host keys are pinned and verified **before** any authentication method is offered. A changed key is a hard failure. Trust-on-first-use is refused outright for reusable secrets. Clearing a pin requires a reason and is audited.

**Residual:** first-connect trust, where allowed, trusts whatever answers. That is why it is permitted only for certificates, whose exposure is bounded to the TTL.

### The gateway as an SSRF pivot

**Impact:** an attacker makes the gateway connect somewhere it should not — cloud metadata, an internal service.

**Controls:** the gateway dials **IP literals only** and links no DNS resolver. Addresses come from LibreNMS's own device record. Loopback, link-local (including `169.254.169.254`), multicast and unspecified addresses are refused. Private ranges are deliberately permitted, because that is where managed equipment lives.

### Turning the session around

**Impact:** a compromised device uses the SSH session to reach back into the monitoring network.

**Controls:** every channel the server opens is refused — X11, agent forwarding, `direct-tcpip`. Exactly one PTY session channel is opened. **Agent forwarding is not implemented at all**, because forwarding an operator's agent into the NMS would make it a single point of credential theft for the whole estate.

### Cross-site WebSocket hijacking

**Impact:** a page on another origin opens a terminal using the victim's cookies.

**Controls:** `Origin` is checked against an allow-list, denying by default including when the list is empty. SameSite cookies do **not** prevent this — the WebSocket handshake is not subject to CORS — so this check, not the cookie, is the control.

### Log injection

**Impact:** attacker-controlled text rewrites what an operator sees when reading logs.

**Controls:** all text entering the audit trail passes through one sanitiser that strips escape sequences and collapses newlines. Log files are read in terminals, so the terminal is part of the threat model.

### Tampering with the audit trail

**Impact:** an intruder erases the record.

**Controls:** security-relevant events are written **off-box before** the database. The table is append-only, enforced in code.

**Residual:** an attacker with database access can still rewrite the local table. The syslog stream is the copy that survives, and only if you configured one.

## What is explicitly out of scope in v1.0

- **Session recording.** Not implemented. The audit trail records *that* a shell was opened, not what was done in it.
- **Break-glass credentials.** WebTerm fails closed when Vault is unavailable. A second credential store would have its own blast radius.
- **Cryptographic operator attribution.** `key_id` is asserted by the LibreNMS host, not proved.
- **Protection against a malicious LibreNMS administrator.** They can grant themselves access. The audit trail records it; nothing prevents it.
