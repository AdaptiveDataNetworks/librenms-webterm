# Should you enable this?

This page exists because the honest answer is sometimes **no**, and you deserve to reach that conclusion before you install rather than after.

## What you are actually doing

LibreNMS knows about every device you monitor and can reach all of them. That reachability is the entire point of a monitoring system, and it is also the broadest network access any single host in your organisation has. This plugin adds the ability to open an interactive shell across that reachability, driven from a web session.

In security terms: **you are turning your NMS into a jump host.** Commercial products do exactly this — SolarWinds documents that its web SSH terminal connects *from the polling engine* — so this is a normal thing to want. It is still a decision that deserves a deliberate yes.

## Say no, for now, if any of these are true

**You cannot commit to patching LibreNMS promptly.** LibreNMS is a large PHP web application with a normal CVE history. Before this plugin, a web vulnerability meant an attacker could read your monitoring data. After it, the same vulnerability can mean shells on your core switches.

**LibreNMS is reachable from the internet without a second access control layer.** A VPN, an identity-aware proxy, or an IP allow-list in front of LibreNMS is a hard prerequisite in our view.

**You have no out-of-band access to your devices.** This plugin fails closed. If Vault is unreachable, or the gateway is down, or the network path between LibreNMS and a device is broken, you cannot open a shell — and those conditions correlate exactly with the incidents where you most want one. WebTerm is a convenience layer, never your only path to a device. Keep your console servers.

**Your operators share a LibreNMS login.** Everything this plugin records about *who* did *what* is attributed to a LibreNMS user account. Shared accounts make the audit trail worthless.

## The honest limits of our controls

We would rather you know these up front than discover them during an incident.

**A compromised LibreNMS host means fleet-wide access.** With the Vault driver, the LibreNMS server must be able to request certificate signing — so an attacker with code execution on that host can request certificates too. Short TTLs, pinned principals, per-group signer roles and Vault's own audit device raise the cost and make it visible. **None of them prevent it.** This is inherent to any design where the NMS can reach devices on your behalf.

**Operator attribution is a claim, not a proof.** The `key_id` recorded in your device's auth log says what LibreNMS told it to say. An attacker who controls LibreNMS can put any name there. The authoritative record is Vault's audit device, on a system your LibreNMS administrator does not control — and only if you have enabled one.

**Browser-resident malware defeats every control here.** Anything executing in an operator's authenticated browser session can drive the step-up prompt and open a session. Step-up, single-use tickets and short TTLs all assume the browser is not the adversary.

**Some old equipment is out of reach.** The gateway does not implement `aes192-cbc`, `aes256-cbc` or `hmac-md5`. Devices offering only those cannot be reached, by design — they remain on your existing `ssh://` links.

## If you proceed

The defaults are closed and stay closed until you open them deliberately:

- The plugin ships disabled.
- No browser origin is allow-listed, so no socket can be opened.
- No device is terminal-enabled.
- No user holds a grant.
- Host-key pinning is required, and trust-on-first-use is refused outright for any credential that could be replayed.
- Step-up authentication is on.

Work through them in that order. Then read the [threat model](../architecture/index.md) and decide whether the residual risk is one your organisation accepts.
