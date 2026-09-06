# Uninstalling

## Remove the plugin

```bash
# as the librenms user
./lnms plugin:remove adn/librenms-webterm
```

This deregisters it so `daily.sh` stops reinstating it on the next LibreNMS update.

## Remove the gateway

```bash
# as root
systemctl disable --now librenms-webterm-gw
apt remove librenms-webterm-gw        # or dnf remove, or rm /usr/bin/librenms-webterm-gw
```

Package removal deliberately **leaves** `/etc/librenms-webterm/` and the `librenms-webterm` user in place: removing them would silently break a reinstall. Delete them yourself if you mean it:

```bash
# as root
rm -rf /etc/librenms-webterm
userdel librenms-webterm
```

## Data left in LibreNMS

The plugin's tables are not dropped on removal, so a reinstall keeps your grants, host key pins and audit history. To remove them:

```bash
# as the librenms user, BEFORE removing the plugin
./lnms migrate:rollback --path=vendor/adn/librenms-webterm/database/migrations
```

!!! warning "This destroys the audit trail"

    `webterm_audit` records who opened a shell on what. Export it before rolling back if you are subject to any retention obligation — and note that the off-box syslog copy, if you configured one, is unaffected and may be the record that actually matters.

## Devices

Nothing is changed on your devices, with one exception: if you used Vault's SSH signer, they still trust the CA public key in `TrustedUserCAKeys`. Remove it if you are decommissioning that CA.
