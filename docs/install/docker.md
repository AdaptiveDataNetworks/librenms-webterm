# Installing alongside Dockerised LibreNMS

The official LibreNMS image runs nginx, php-fpm and snmpd under one supervisor. A Composer package cannot add a daemon to that container, so the gateway is a **separate service** on the same compose network.

## 1. The plugin

```bash
docker compose exec --user librenms librenms ./lnms plugin:add adaptivedatanetworks/librenms-webterm
docker compose exec --user librenms librenms php artisan route:clear
```

Enable it under **Overview → Plugins → Plugin Admin**.

!!! warning "Plugins and image upgrades"

    A plugin installed inside a container lives in that container's filesystem. Pulling a new image discards it unless `/opt/librenms` is on a volume. Check your compose file before upgrading, and see [LibreNMS updates](librenms-updates.md).

## 2. Generate a shared secret

```bash
docker run --rm ghcr.io/adaptivedatanetworks/librenms-webterm-gw:X.Y.Z init --path /dev/stdout > ./webterm_gateway_secret
chmod 0640 ./webterm_gateway_secret
```

Both LibreNMS and the gateway must read this file.

## 3. Add the gateway service

```yaml
services:
  webterm-gateway:
    image: ghcr.io/adaptivedatanetworks/librenms-webterm-gw:X.Y.Z
    restart: unless-stopped
    expose:
      - "8377"
    environment:
      WEBTERM_LISTEN: "0.0.0.0:8377"
      WEBTERM_INSECURE_CONTROL_PLANE: "true"
      WEBTERM_SECRET_FILE: /run/secrets/webterm_gateway_secret
      WEBTERM_ALLOWED_ORIGINS: https://librenms.example.com
    secrets:
      - webterm_gateway_secret
    networks:
      - librenms
    read_only: true
    cap_drop: [ALL]
    security_opt:
      - no-new-privileges:true

secrets:
  webterm_gateway_secret:
    file: ./webterm_gateway_secret
```

!!! note "Why the loopback guard is waived here, and only here"

    The gateway normally refuses to bind anything but loopback, because the control plane has no transport security. Inside a container with **no published ports**, `0.0.0.0` is reachable only from the compose network — which is the equivalent boundary. Do not copy `WEBTERM_INSECURE_CONTROL_PLANE=true` onto a bare-metal install, and never add a `ports:` mapping for 8377.

## 4. Point LibreNMS at it

```bash
docker compose exec --user librenms librenms ./lnms webterm:config set \
  gateway.url http://webterm-gateway:8377
docker compose exec --user librenms librenms ./lnms webterm:config set \
  gateway.secret_file /run/secrets/webterm_gateway_secret
```

Mount the same secret into the LibreNMS container.

## 5. Proxy the WebSocket

The browser must reach `/webterm/ws` on the LibreNMS origin. If you terminate TLS at Traefik, nginx or Caddy in front of the stack, route that path to `webterm-gateway:8377` — see [reverse proxy](../operate/reverse-proxy.md).

## 6. Verify

```bash
docker compose exec --user librenms librenms ./lnms webterm:doctor
```
