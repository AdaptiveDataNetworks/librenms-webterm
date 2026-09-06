# Reverse proxy

The browser reaches the gateway through your existing LibreNMS vhost. One path needs proxying: `/webterm/ws`.

Three settings below are load-bearing. Omitting any of them produces a symptom that looks like a bug somewhere else, and between them they account for most reported problems.

## nginx

```nginx
map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}

server {
    # ... your existing LibreNMS configuration ...

    location ^~ /webterm/ws {
        proxy_pass http://127.0.0.1:8377;

        # HTTP/1.0 has no Upgrade semantics, and nginx talks 1.0 upstream by
        # default. Without this the handshake simply never completes.
        proxy_http_version 1.1;
        proxy_set_header Upgrade    $http_upgrade;
        proxy_set_header Connection $connection_upgrade;

        proxy_set_header Host       $host;
        proxy_set_header X-Real-IP  $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;

        # A terminal is an interactive stream. Buffered, it arrives in bursts
        # and feels broken.
        proxy_buffering off;

        # THE DEFAULT IS 60 SECONDS. Without raising it, every idle terminal
        # dies after a minute and presents as an unexplained hang.
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
        proxy_connect_timeout 10s;

        proxy_socket_keepalive on;
    }

    location ^~ /webterm/ui/ {
        proxy_pass http://127.0.0.1:8377/ui/;
        proxy_set_header Host $host;
    }
}
```

Use the `map` rather than a literal `Connection "upgrade"`: a literal breaks keepalive for every non-WebSocket request routed through the same block.

```bash
# as root
nginx -t && systemctl reload nginx
```

## Apache

```apache
# a2enmod proxy proxy_http proxy_wstunnel
ProxyPreserveHost On

ProxyPass        /webterm/ws  ws://127.0.0.1:8377/ws  upgrade=websocket timeout=3600
ProxyPassReverse /webterm/ws  ws://127.0.0.1:8377/ws

ProxyPass        /webterm/ui/ http://127.0.0.1:8377/ui/
ProxyPassReverse /webterm/ui/ http://127.0.0.1:8377/ui/
```

Order matters: these must come **before** any catch-all `ProxyPass` for LibreNMS itself.

Since httpd 2.4.47, `mod_proxy_http` handles the upgrade and `mod_proxy_wstunnel` hands over to it. Never use `upgrade=ANY` — the Apache documentation says plainly it is not recommended in production.

!!! note "HTTP/2"

    HTTP/2 has no `Upgrade` header, and `mod_proxy_wstunnel` does not implement RFC 8441. Browsers negotiate WebSockets over HTTP/1.1, so this normally just works — but if something forces h2 for the upgrade request, it fails confusingly. Keep `http/1.1` in your `Protocols` list.

## Traefik

```yaml
labels:
  - traefik.http.routers.webterm.rule=Host(`librenms.example.com`) && PathPrefix(`/webterm/`)
  - traefik.http.services.webterm.loadbalancer.server.port=8377
  - traefik.http.middlewares.webterm-strip.stripprefix.prefixes=/webterm
  - traefik.http.routers.webterm.middlewares=webterm-strip
```

Traefik handles WebSocket upgrades without extra configuration. Its default `respondingTimeouts.readTimeout` of 0 means no timeout, which is what you want here.

## Verifying

Open the browser network tab and look at `/webterm/ws`. A working proxy shows `101 Switching Protocols`. Anything else is diagnosed in [gateway troubleshooting](../gateway/troubleshooting.md).
