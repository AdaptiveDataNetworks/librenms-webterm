# FAQ

**Does this replace the `ssh://` links?**

It complements them. Those links still work and remain the right answer for devices WebTerm cannot reach — anything needing `aes192-cbc`, `aes256-cbc` or `hmac-md5`, which Go's SSH library does not implement.

**Why a separate gateway instead of pure PHP?**

PHP-FPM cannot hold a WebSocket: a FastCGI worker's execution context is destroyed at the end of each request. LibreNMS also ships no queue worker, no broadcasting configuration and no WebSocket infrastructure to borrow. A separate long-lived process is the only workable answer.

A pure-PHP gateway *was* seriously considered — `phpseclib` is already in LibreNMS's lockfile, which would have made it dependency-free. It was rejected because phpseclib cannot use OpenSSH signed certificates, and those are the entire point of the Vault integration.

**Does it record what people type?**

No. v1.0 records *that* a shell was opened, on what, by whom and when — not the session content. That is a deliberate omission: session recording is a large subsystem with unbounded disk growth, fragile secret redaction, and a jurisdiction-specific legal dimension. Doing it badly would be worse than not doing it.

**Can I use it for RDP?**

Not in v1.0. The schema reserves a protocol column so a future Guacamole-backed handler is a new row rather than a migration, but nothing is implemented.

**Does it work with LibreNMS in Docker?**

Yes — the gateway is a separate compose service, since a Composer package cannot add a daemon to the official image. See [Docker](install/docker.md).

**Do I need Vault?**

No. The database driver needs nothing extra. Vault is strongly preferable beyond a small estate, because it removes reusable secrets entirely. See [credentials](credentials/index.md).

**Why can't I open a terminal even though I'm an admin?**

Being a LibreNMS administrator grants no terminal access. That is deliberate: LibreNMS's own view permission is held by every read-only account, and treating it as shell permission would hand terminals to all of them.

```bash
./lnms webterm:why --user=you --device=core-sw-01
```

**Why does the terminal die after about a minute?**

`proxy_read_timeout` in nginx defaults to 60 seconds. See [reverse proxy](operate/reverse-proxy.md).

**Can I turn off the two-factor prompt?**

```bash
./lnms webterm:config set security.step_up false
```

Consider what it costs you first: without step-up, a stolen session cookie is sufficient to open a shell on your network.

**Is it safe to run the gateway on the LibreNMS server?**

It is the normal deployment, and it is what makes the install simple. It does mean a compromise of either is a compromise of both. LibreNMS already has the network access that matters, so for most installations this changes less than it sounds — but see [requirements](install/requirements.md) if your threat model differs.

**What happens if Vault goes down?**

No terminal can be opened. WebTerm fails closed deliberately, which is why out-of-band access to your devices is a prerequisite rather than a nicety.

**Is this affiliated with LibreNMS?**

No. It is an independent plugin, licensed GPL-3.0-or-later to match LibreNMS, and not endorsed by the LibreNMS project or by HashiCorp.
