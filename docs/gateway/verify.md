# Verifying the gateway

## From LibreNMS

```bash
# as the librenms user
./lnms webterm:doctor
```

This checks both halves and every failure names its fix. If it passes, the plugin can reach the gateway, they agree on the protocol, and the shared secret matches.

Add `--deep` to also check that every pinned target actually has a host key.

## From the gateway host

```bash
librenms-webterm-gw version
curl -s http://127.0.0.1:8377/healthz
```

## From the browser

The most common failure is not the gateway at all — it is the reverse proxy. Open your browser's network tab and look at the `/webterm/ws` request:

| What you see | What it means |
|---|---|
| `101 Switching Protocols` | Working |
| `403` with `X-WebTerm-Reject: origin` | `WEBTERM_ALLOWED_ORIGINS` does not match your browser's origin |
| `404` | The proxy is not routing `/webterm/ws` to the gateway |
| `502` | The proxy is routing it, but the gateway is not running |
| Connects, then closes after ~60s | `proxy_read_timeout` — see [reverse proxy](../operate/reverse-proxy.md) |

The `X-WebTerm-Reject` header exists precisely so origin problems are diagnosable from the browser. A silent close is indistinguishable from a proxy timeout, and operators lose hours to that.

## End to end

The honest test is a real session. From a device page, click **Open terminal**. If it fails, `webterm:why` tells you whether the refusal was authorization:

```bash
# as the librenms user
./lnms webterm:why --user=you --device=core-sw-01
```

If authorization passes and the terminal still does not open, the problem is transport — go back to the network tab.
