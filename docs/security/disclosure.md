# Reporting a security issue

**Please do not open a public issue.**

Report privately through [GitHub Security Advisories](https://github.com/AdaptiveDataNetworks/librenms-webterm/security/advisories/new), which lets us discuss and prepare a fix before anything becomes public.

Include the plugin and gateway versions, your LibreNMS version, the credential driver in use, and enough detail to reproduce. If you have a proof of concept, say so — you do not need to attach it in the first message.

We aim to acknowledge within 72 hours, will keep you updated, and will credit you in the advisory unless you would rather we did not.

## What counts

The project's security claims are specific, so it is worth stating what would break them:

- Opening a shell on a device a user has not been granted.
- Obtaining credential material from anywhere other than the loopback credential message — a log, a URL, a cookie, the DOM, a cache entry, an error page, a support bundle.
- Bypassing the origin check, the single-use ticket, or step-up authentication.
- Causing the gateway to connect somewhere the plugin did not specify.
- Escaping the single PTY session channel — port forwarding, agent access, subsystem or exec.
- Privilege escalation on the gateway host.

## What is known

These are documented properties of the design, discussed in the [threat model](threat-model.md):

- A compromised LibreNMS host can obtain credentials for devices it is configured to reach.
- `key_id` attribution is a claim, not cryptographic proof.
- Script running in an operator's authenticated browser session can drive a connection.
- WebTerm fails closed when its credential backend is unreachable.

Reports of these are welcome as discussion but will be closed as documented rather than fixed.

## Scope

This project is the plugin and the gateway. Vulnerabilities in LibreNMS itself go to the [LibreNMS project](https://github.com/librenms/librenms/security); vulnerabilities in Vault go to HashiCorp.
