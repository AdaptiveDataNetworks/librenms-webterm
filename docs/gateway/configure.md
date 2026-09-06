# Configuring the gateway

All configuration is environment variables, read from `/etc/librenms-webterm/gateway.env` by the systemd unit. Environment rather than a config file so the unit and the container configure identically — and so no secret ever passes through a file we also parse for other settings.

## Required

```bash
# The browser origins permitted to open a terminal.
# With none set, EVERY connection is refused. This is deliberate.
WEBTERM_ALLOWED_ORIGINS=https://librenms.example.com
```

Use the exact origin your browser shows, scheme included. `https://librenms.example.com` and `https://librenms.example.com:443` are the same origin; `http://` and `https://` are not.

## The shared secret

```bash
WEBTERM_SECRET_FILE=/etc/librenms-webterm/gateway.secret
```

A **path**, never the secret itself. Environment variables are visible in `/proc`, in `systemctl show`, and in crash dumps and support bundles; a file has an owner and a mode.

Generate one with:

```bash
# as root
librenms-webterm-gw init --path /etc/librenms-webterm/gateway.secret
```

The gateway refuses to start if the secret is missing, shorter than 32 bytes, or equal to any value printed in this documentation.

## Listeners

```bash
WEBTERM_LISTEN=127.0.0.1:8377
WEBTERM_METRICS_LISTEN=127.0.0.1:8378
```

The gateway **refuses to bind a non-loopback address**. The control plane has no transport security, and anything that can reach the port can create a session. The browser reaches it through your LibreNMS reverse proxy.

The only supported exception is a container with no published ports, where the compose network is the equivalent boundary:

```bash
WEBTERM_INSECURE_CONTROL_PLANE=true
```

`webterm:doctor` reports when this is set, every time, because it is not a setting anyone should forget they made.

## Limits

```bash
WEBTERM_MAX_SESSIONS=100
WEBTERM_LOG_LEVEL=info
```

Per-session idle and duration limits come from LibreNMS with each session, so they follow the grant rather than the gateway.

## Full reference

| Variable | Default | Notes |
|---|---|---|
| `WEBTERM_LISTEN` | `127.0.0.1:8377` | Loopback enforced |
| `WEBTERM_METRICS_LISTEN` | `127.0.0.1:8378` | Always loopback |
| `WEBTERM_SECRET_FILE` | `/etc/librenms-webterm/gateway.secret` | Path, not value |
| `WEBTERM_ALLOWED_ORIGINS` | *(none)* | Comma-separated; empty means deny all |
| `WEBTERM_MAX_SESSIONS` | `100` | Pending plus live |
| `WEBTERM_LOG_LEVEL` | `info` | `debug`, `info`, `warn`, `error` |
| `WEBTERM_INSECURE_CONTROL_PLANE` | `false` | Containers only |
