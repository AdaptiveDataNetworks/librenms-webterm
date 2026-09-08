<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| LibreNMS WebTerm
|--------------------------------------------------------------------------
|
| Every value here is safe to commit. Secrets are NEVER read from this file:
| the control-plane shared secret is read from a file path, and credential
| material comes from the configured credential provider.
|
| Defaults are deliberately closed. A fresh install cannot open a shell to
| anything until an administrator explicitly enables the plugin, enables a
| target, and grants a user access.
|
*/

return [

    // Global kill switch. When false, every session-mint route returns 403 and
    // the device panel renders a disabled state. Checked as step 0 of admit().
    'enabled' => env('WEBTERM_ENABLED', false),

    'gateway' => [
        // Control-plane base URL. Loopback by default; the gateway refuses to
        // bind a non-loopback address without an explicit insecure override.
        'url' => env('WEBTERM_GATEWAY_URL', 'http://127.0.0.1:8377'),

        // Browser-facing WebSocket URL. Must be same-origin behind the LibreNMS
        // reverse proxy, or an explicitly allow-listed origin.
        'ws_url' => env('WEBTERM_GATEWAY_WS_URL', '/webterm/ws'),

        // Browser-facing path for the terminal's own assets, which the gateway
        // embeds and serves rather than adding anything to LibreNMS's asset
        // build. Needs its own reverse-proxy location; without one the terminal
        // page loads and its frame 404s against LibreNMS.
        'ui_url' => env('WEBTERM_GATEWAY_UI_URL', '/webterm/ui/'),

        // Path to the 32-byte shared secret. A PATH, never the secret itself --
        // config values land in `config:cache` output, crash dumps and support
        // bundles. The gateway reads the same file.
        'secret_file' => env('WEBTERM_GATEWAY_SECRET_FILE', '/etc/librenms-webterm/gateway.secret'),

        // Hard caps. A blackholed gateway must never consume a php-fpm worker.
        'connect_timeout_ms' => 2000,
        'timeout_ms' => 5000,

        // Consecutive failures before the circuit opens, and how long it stays open.
        'circuit_breaker' => [
            'threshold' => 3,
            'cooldown_seconds' => 30,
        ],
    ],

    'credentials' => [
        // 'database' | 'vault'
        'driver' => env('WEBTERM_CREDENTIAL_DRIVER', 'database'),

        'database' => [
            // Optional dedicated key. When unset, a key is derived from APP_KEY
            // via HKDF so that credentials are not encrypted with the same key
            // as sessions and cookies.
            'key' => env('WEBTERM_CREDENTIAL_KEY'),
            'cipher' => 'aes-256-gcm',
        ],

        'vault' => [
            'address' => env('WEBTERM_VAULT_ADDR', 'http://127.0.0.1:8200'),
            'namespace' => env('WEBTERM_VAULT_NAMESPACE'),

            // 'agent' | 'approle'
            'auth' => env('WEBTERM_VAULT_AUTH', 'agent'),

            'agent' => [
                'address' => env('VAULT_AGENT_ADDR', 'unix:///run/vault-agent/agent.sock'),
            ],

            'approle' => [
                'mount' => env('WEBTERM_VAULT_APPROLE_MOUNT', 'approle'),
                'role_id' => env('WEBTERM_VAULT_ROLE_ID'),
                // Path to the SecretID on disk, mode 0400, owned by the web user.
                'secret_id_file' => env('WEBTERM_VAULT_SECRET_ID_FILE'),
            ],

            'ssh_signer' => [
                'mount' => env('WEBTERM_VAULT_SSH_MOUNT', 'ssh-client-signer'),
                'role' => env('WEBTERM_VAULT_SSH_ROLE', 'librenms-webterm'),
                'ttl' => '30m',
            ],

            'kv2' => [
                'mount' => env('WEBTERM_VAULT_KV_MOUNT', 'secret'),

                // Only {device_id}, {hostname} and {principal} are substituted,
                // each validated and URL-encoded. A richer template language
                // here would be a path-traversal surface into a secret store.
                'path_template' => env('WEBTERM_VAULT_KV_PATH', 'librenms/devices/{device_id}'),

                'field_map' => [
                    'password' => env('WEBTERM_VAULT_KV_PASSWORD_FIELD', 'password'),
                    'private_key' => env('WEBTERM_VAULT_KV_KEY_FIELD', 'private_key'),
                ],
            ],

            'tls' => [
                'verify' => env('WEBTERM_VAULT_TLS_VERIFY', true),
                'ca_cert' => env('WEBTERM_VAULT_CACERT'),
            ],
        ],
    ],

    'session' => [
        'idle_timeout' => 900,
        'max_duration' => 14400,
        'warn_at' => [300, 60],
        'max_concurrent_per_user' => 3,
        'reconcile_interval' => 15,
    ],

    'security' => [
        // Require a fresh TOTP challenge before opening a shell, even for an
        // already-authenticated LibreNMS user. LibreNMS's own login 2FA does
        // NOT satisfy this -- see StepUpVerifier.
        'step_up' => env('WEBTERM_STEP_UP', true),
        'step_up_grace_seconds' => 900,
        'step_up_absolute_cap_seconds' => 28800,

        // Deny-by-default. An empty list means no browser origin may open a
        // socket, which is the correct posture until configured.
        'allowed_origins' => array_filter(explode(',', (string) env('WEBTERM_ALLOWED_ORIGINS', ''))),

        // 'pin' forbids trust-on-first-use. Forced regardless for any flow that
        // carries a reusable secret (password / private key).
        'host_key_policy' => env('WEBTERM_HOST_KEY_POLICY', 'pin'),
    ],

    'audit' => [
        'syslog' => env('WEBTERM_AUDIT_SYSLOG', true),
        'json_file' => env('WEBTERM_AUDIT_JSON_FILE'),
        'mirror_to_eventlog' => true,
        'retention_days' => 400,
    ],
];
