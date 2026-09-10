# LibreNMS WebTerm

An in-browser SSH terminal for the devices LibreNMS already monitors, opened from the device page instead of handing you off to a local client through an `ssh://` link.

Credentials come from **HashiCorp Vault** — ideally as short-lived signed SSH certificates, so no reusable secret is ever stored — or from **encrypted rows in the LibreNMS database** if you are a smaller shop without a Vault deployment.

!!! warning "Read the security guide first"

    This plugin gives your monitoring system the ability to open interactive shells on your network. That is a significant, deliberate change to your security posture. [Should you enable this?](security/index.md) is an honest guide to when the answer is *no*.

## Installing

One command on the LibreNMS server, as root:

```bash
curl -fsSLO https://github.com/AdaptiveDataNetworks/librenms-webterm/releases/latest/download/install.sh
less install.sh
sh install.sh
```

It adds the signed [package repository](install/package-repo.md), installs the
gateway and the plugin, configures the reverse proxy and verifies the result.
The [quickstart](getting-started/quickstart.md) takes it from there to a working
terminal.

## Where to start

| You are | Start here |
|---|---|
| Trying it out | [Quickstart](getting-started/quickstart.md) |
| Deploying with Vault | [HashiCorp Vault](vault/index.md) |
| Reviewing it for your organisation | [Should you enable this?](security/index.md) |
| About to update LibreNMS | [LibreNMS updates](install/librenms-updates.md) |
| Curious how it works | [Architecture](architecture/index.md) |
