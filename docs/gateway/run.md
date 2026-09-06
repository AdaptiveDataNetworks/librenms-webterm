# Running the gateway

## Service

```bash
# as root
systemctl enable --now librenms-webterm-gw
systemctl status librenms-webterm-gw
journalctl -u librenms-webterm-gw -f
```

Logs are JSON, one object per line, so they survive shipping intact:

```json
{"time":"2026-09-06T11:28:17Z","level":"INFO","msg":"gateway starting","version":"1.0.0","instance_id":"gw-e1c5ecc2","listen":"127.0.0.1:8377","protocol":1,"allowed_origins":1}
```

A credential never appears in these logs at any level, including `debug`. There is a test asserting it.

## Health

```bash
curl -s http://127.0.0.1:8377/healthz
curl -s http://127.0.0.1:8377/readyz
```

`/readyz` reports `draining` during a graceful shutdown, so a load balancer can stop sending it work before sessions end.

## Restarting

Restarting ends live sessions. Each is sent an in-band notice first, so the operator sees "the gateway is restarting, reconnect in a moment" rather than an unexplained disconnect — but it is still a disconnect. Pick your moment.

## What it does not do

- It never connects to LibreNMS. Every control-plane request is inbound.
- It never resolves DNS. Addresses arrive as IP literals from LibreNMS.
- It never writes to disk. It reads one secret at startup.
- It holds no database credentials, no Vault token and no LibreNMS session.

That list is the reason a gateway compromise is bounded: there is very little there to steal beyond the sessions currently running.
