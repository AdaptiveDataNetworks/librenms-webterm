<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Support;

/**
 * Which settings the console may change, and which it may only show.
 *
 * Curated rather than reflective, because this plugin has already shipped the
 * opposite twice: `webterm:config set` wrote rows nothing read, and an
 * allowed_origins key lived in plugin config while the gateway took its own
 * from the environment. A control that appears to work and does nothing is
 * worse than no control, so a key earns its place here only if something reads
 * it per request.
 *
 * Everything not listed as editable is shown read-only WITH ITS REASON, not
 * hidden -- an operator hunting for a setting should find it and learn why it
 * is not theirs to change here.
 */
final class EditableSettings
{
    /**
     * key => [type, label, help]
     *
     * @return array<string, array{string, string, string}>
     */
    public static function editable(): array
    {
        return [
            'enabled' => ['bool', 'Enabled', 'The global kill switch. Off means no terminal opens, whatever else is configured.'],

            'session.idle_timeout' => ['int', 'Idle timeout (seconds)', 'A terminal with no traffic for this long is closed.'],
            'session.max_duration' => ['int', 'Maximum duration (seconds)', 'A session is closed at this age however active it is.'],
            'session.max_concurrent_per_user' => ['int', 'Concurrent sessions per user', 'How many terminals one operator may hold at once.'],
            'session.reconcile_interval' => ['int', 'Reconcile interval (seconds)', 'How often live sessions are re-authorised against current grants.'],

            'security.step_up' => ['bool', 'Require step-up', 'Require LibreNMS two-factor at connect time, independently of the login session.'],
            'security.step_up_grace_seconds' => ['int', 'Step-up grace (seconds)', 'How long one step-up covers further connections.'],
            'security.step_up_absolute_cap_seconds' => ['int', 'Step-up absolute cap (seconds)', 'The grace cannot be renewed past this.'],
            'security.host_key_policy' => ['enum:pin,tofu_first_connect', 'Default host key policy', 'Applied to targets that do not set their own.'],

            'audit.syslog' => ['bool', 'Mirror audit to syslog', 'Security-relevant events leave the host before the local write.'],
            'audit.mirror_to_eventlog' => ['bool', 'Mirror audit to the LibreNMS eventlog', 'Events appear on the device page.'],
            'audit.retention_days' => ['int', 'Audit retention (days)', 'How long local audit rows are kept.'],

            'gateway.connect_timeout_ms' => ['int', 'Gateway connect timeout (ms)', 'A blackholed gateway must not consume a php-fpm worker.'],
            'gateway.timeout_ms' => ['int', 'Gateway request timeout (ms)', 'Upper bound on any single control-plane call.'],
        ];
    }

    /**
     * key => why it cannot be edited here.
     *
     * @return array<string, string>
     */
    public static function readOnly(): array
    {
        return [
            'gateway.secret_file' => 'A path to the shared secret. Changing it from a browser could point LibreNMS at a different secret than the gateway is using, and every session mint would fail with an opaque 401. Set it with webterm:config on the host.',
            'credentials.database.key' => 'The credential encryption key. It is read from the environment so it never lands in config:cache output, a crash dump or a support bundle.',
            'security.allowed_origins' => 'Owned by the gateway, not by this plugin: it reads WEBTERM_ALLOWED_ORIGINS from /etc/librenms-webterm/gateway.env. A value set here would be read by nothing.',
            'gateway.url' => 'The control-plane address, loopback by design. Changing it is a deployment decision, not a runtime one.',
            'gateway.ws_url' => 'Must match a reverse-proxy location you maintain by hand. Changing it here without changing nginx breaks every terminal.',
            'gateway.ui_url' => 'Must match a reverse-proxy location you maintain by hand.',
            'credentials.driver' => 'Chooses between the database and Vault. Switching it at runtime would change which secret store every device resolves against, mid-flight.',
        ];
    }

    /**
     * Coerce and validate a submitted value against the declared type.
     *
     * @return array{bool, int|bool|string, string} ok, value, error
     */
    public static function coerce(string $key, string $raw): array
    {
        $spec = self::editable()[$key] ?? null;

        if ($spec === null) {
            return [false, '', 'not an editable setting'];
        }

        [$type] = $spec;

        if ($type === 'bool') {
            return [true, in_array(strtolower($raw), ['1', 'true', 'on', 'yes'], true), ''];
        }

        if ($type === 'int') {
            // ctype_digit('') is already false, so an empty value is covered.
            if (! ctype_digit($raw)) {
                return [false, 0, 'must be a whole number'];
            }

            return [true, (int) $raw, ''];
        }

        if (str_starts_with($type, 'enum:')) {
            $allowed = explode(',', substr($type, 5));

            if (! in_array($raw, $allowed, true)) {
                return [false, '', 'must be one of: '.implode(', ', $allowed)];
            }

            return [true, $raw, ''];
        }

        return [false, '', 'unsupported type'];
    }
}
