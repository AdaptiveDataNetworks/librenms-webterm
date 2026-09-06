# LibreNMS WebTerm

An in-browser SSH terminal for the devices LibreNMS already monitors, opened from the device page instead of handing you off to a local client through an `ssh://` link.

Credentials come from **HashiCorp Vault** — ideally as short-lived signed SSH certificates, so no reusable secret is ever stored — or from **encrypted rows in the LibreNMS database** if you are a smaller shop without a Vault deployment.

!!! warning "Read the security guide first"

    This plugin gives your monitoring system the ability to open interactive shells on your network. That is a significant, deliberate change to your security posture. [Should you enable this?](security/index.md) is an honest guide to when the answer is *no*.

## Where to start

| You are | Start here |
|---|---|
| Trying it out | [Quickstart](getting-started/quickstart.md) |
| Deploying with Vault | [HashiCorp Vault](vault/index.md) |
| Reviewing it for your organisation | [Should you enable this?](security/index.md) |
| About to update LibreNMS | [LibreNMS updates](install/librenms-updates.md) |
| Curious how it works | [Architecture](architecture/index.md) |
