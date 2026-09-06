# Gateway troubleshooting

Symptom first. Every entry ends with something to run.

## The terminal button does not appear

The panel renders nothing at all when the device is not an enabled target — most devices in an estate never will be, and a permanent "not configured" box on every device page would be noise.

```bash
./lnms webterm:target:enable --device=core-sw-01 --principal=netops
./lnms webterm:why --user=you --device=core-sw-01
```

If `webterm:why` says `device_not_visible`, the problem is LibreNMS's own device permissions, not WebTerm.

## "Could not open a terminal"

Run the authoritative check:

```bash
./lnms webterm:why --user=you --device=core-sw-01
```

It runs the real authorization path and prints the exact command that fixes the failing step.

## It connects, then disconnects after about a minute

`proxy_read_timeout` in nginx defaults to **60 seconds**. The gateway pings every 20 seconds, but the proxy has to allow the connection to stay open:

```nginx
proxy_read_timeout 3600s;
```

See [reverse proxy](../operate/reverse-proxy.md).

## Output arrives in bursts, or the terminal feels laggy

Proxy buffering. The terminal is an interactive stream, and buffering it defeats the point:

```nginx
proxy_buffering off;
```

## The browser network tab shows 403 with `X-WebTerm-Reject: origin`

`WEBTERM_ALLOWED_ORIGINS` does not include the origin your browser is using. Use the exact value, scheme included, then restart the gateway.

??? failure "origin not permitted"

    ```bash
    # /etc/librenms-webterm/gateway.env
    WEBTERM_ALLOWED_ORIGINS=https://librenms.example.com
    ```

    ```bash
    systemctl restart librenms-webterm-gw
    ```

## The gateway will not start

??? failure "shared secret matches a value published in documentation"

    Someone copy-pasted an example. Generate a real one:

    ```bash
    librenms-webterm-gw init --path /etc/librenms-webterm/gateway.secret --force
    ```

    Then make sure LibreNMS reads the same file.

??? failure "refusing to bind a non-loopback address"

    The control plane has no transport security. Bind loopback and proxy to it. Only inside a container with no published ports is `WEBTERM_INSECURE_CONTROL_PLANE=true` appropriate.

??? failure "reading shared secret ... no such file or directory"

    ```bash
    librenms-webterm-gw init --path /etc/librenms-webterm/gateway.secret
    ```

## "The gateway rejected our credentials"

LibreNMS and the gateway are reading different secrets.

```bash
# on the gateway host
sha256sum /etc/librenms-webterm/gateway.secret

# as the librenms user
./lnms webterm:config get gateway.secret_file
sha256sum "$(./lnms webterm:config get gateway.secret_file)"
```

The two hashes must match. If LibreNMS cannot read the file at all, add the web user to the `librenms-webterm` group and restart php-fpm — group changes do not affect running processes.

## The host key was rejected

??? failure "The device presented a different SSH host key than the one pinned"

    This is either a legitimate key change or an interception. **Do not clear the pin reflexively.** Compare the fingerprint against the device itself first:

    ```bash
    ./lnms webterm:hostkey-scan --device=core-sw-01
    ```

    If the change is expected:

    ```bash
    ./lnms webterm:hostkey-reset --device=core-sw-01 --reason="firmware upgrade 2026-09-06"
    ./lnms webterm:hostkey-scan --device=core-sw-01
    ```

    The reason is written to the audit trail, because clearing a pin is exactly what an attacker would want done after substituting a device.

## An old device will not connect at all

The gateway uses Go's `x/crypto/ssh`, which does not implement `aes192-cbc`, `aes256-cbc` or `hmac-md5`. No configuration can enable them — they are absent from the library. Try the legacy profile first:

```bash
./lnms webterm:target:enable --device=old-switch --principal=admin --profile=legacy
```

If that does not help, the device is out of reach and should stay on your existing `ssh://` links or a console server. See [compatibility](../reference/compatibility.md).

## Sessions show as live in LibreNMS but not on the gateway

The reconciler is not running.

```bash
./lnms webterm:reconcile
```

If that fixes it, Laravel's scheduler is not firing. Check your LibreNMS cron.
