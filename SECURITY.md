# Security policy

## Reporting a vulnerability

**Please do not open a public issue for a security problem.**

Report privately through [GitHub Security Advisories](https://github.com/AdaptiveDataNetworks/librenms-webterm/security/advisories/new), which lets us discuss and prepare a fix before anything is public.

Please include the plugin and gateway versions, your LibreNMS version, the credential driver in use, and enough detail to reproduce. If you have a proof of concept, say so — you do not need to attach it in the first message.

We aim to acknowledge within 72 hours. We will keep you updated as we work, and credit you in the advisory unless you would rather we did not.

## What we consider a vulnerability

This project's security claims are specific, so it helps to state what would count as breaking them:

- Opening a shell on a device a user has not been granted.
- Obtaining credential material from anywhere other than the loopback credential message — a log, a URL, a cookie, the DOM, a cache entry, an error page, a support bundle.
- Bypassing the origin check, the single-use ticket, or step-up authentication.
- Causing the gateway to connect somewhere the plugin did not specify.
- Escaping the single PTY session channel — port forwarding, agent access, subsystem or exec.
- Privilege escalation on the gateway host.

## What is known, and not a vulnerability

These are documented properties of the design, discussed in [Should you enable this?](docs/security/index.md):

- **A compromised LibreNMS host can obtain credentials for devices it is configured to reach.** Inherent to any design where the NMS connects on your behalf.
- **`key_id` attribution is a claim, not cryptographic proof of operator identity.**
- **Script running in an operator's authenticated browser session can drive a connection.** Every control here assumes the browser is not the adversary.
- **WebTerm fails closed when its credential backend is unreachable.** Deliberate.

Reports of these are still welcome as discussion, but they will be closed as documented rather than fixed.

## Supported versions

Only the latest release receives fixes. Plugin and gateway are released
together and must be upgraded together — they must agree on the protocol
version exactly.

## Package signing key

Packages published to `packages.adaptivedatanetworks.com` are signed with:

```text
pub   rsa4096 2026-09-10 [SC] [expires: 2031-09-09]
      1880 C40E 19DC 890F 8F00  8D0C F729 7BB1 4290 D3DF
uid   Adaptive Data Networks Package Signing <packages@adaptivedatanetworks.com>
```

Fetch it from `https://packages.adaptivedatanetworks.com/adn-archive-keyring.asc`
and check the fingerprint against the line above before trusting it. If they do
not match, do not install anything and report it as described above.

The private half exists only on the repository host, in a directory readable by
one unprivileged system account. It is never present in GitHub Actions: the
release workflow holds a key that can run exactly one command on that host —
`publish vX.Y.Z` — and carries no artifact content at all. The host fetches the
release from GitHub itself and verifies the published checksums before signing
anything.
