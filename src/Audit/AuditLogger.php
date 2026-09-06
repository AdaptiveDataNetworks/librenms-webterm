<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Audit;

use AdaptiveDataNetworks\WebTerm\Librenms\EventlogWriter;
use AdaptiveDataNetworks\WebTerm\Models\AuditEntry;
use AdaptiveDataNetworks\WebTerm\Support\Guard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;

/**
 * Writes the audit trail to three places, deliberately.
 *
 *  1. Off-box (syslog or a JSON file another agent ships). For
 *     security-relevant events this happens FIRST, because an attacker who
 *     compromises LibreNMS can rewrite our table but cannot recall a datagram
 *     that has already left the host. That ordering is the tamper-evidence
 *     story -- not a hash chain, which forks under concurrency, serialises the
 *     connect path on one row lock, and breaks permanently the first time
 *     retention prunes an early row.
 *
 *  2. Our own append-only table, which the admin UI reads.
 *
 *  3. LibreNMS's eventlog, so that "someone opened a shell here" appears on the
 *     device page where an operator is already looking. Duplication is the
 *     point.
 *
 * Logging must never break a connection: a failure in any sink is reported and
 * swallowed. The exception is that the off-box write for security events is
 * attempted before anything else, so the most important record has the best
 * chance of surviving.
 */
final class AuditLogger
{
    public function __construct(
        private readonly EventlogWriter $eventlog = new EventlogWriter,
    ) {}

    /**
     * @param  array<string, mixed>  $detail
     */
    public function log(
        Event $event,
        ?Authenticatable $user = null,
        ?int $deviceId = null,
        ?string $sessionId = null,
        ?string $reasonCode = null,
        array $detail = [],
    ): void {
        $now = Carbon::now();
        $username = $this->usernameOf($user);
        $clean = Sanitize::detail($detail);

        $record = [
            'occurred_at' => $now,
            'event' => $event->value,
            'severity' => $event->severity()->value,
            'user_id' => $user?->getAuthIdentifier(),
            'username' => $username,
            'device_id' => $deviceId,
            'session_id' => $sessionId,
            'reason_code' => $reasonCode === null ? null : Sanitize::text($reasonCode, 48),
            'source_ip' => $this->sourceIp(),
            'detail' => $clean === [] ? null : json_encode($clean, JSON_UNESCAPED_SLASHES),
        ];

        if ($event->isSecurityRelevant()) {
            $this->writeOffBox($record);
            $this->writeDatabase($record);
        } else {
            $this->writeDatabase($record);
            $this->writeOffBox($record);
        }

        $this->mirrorToEventlog($event, $deviceId, $username, $reasonCode);
    }

    /** @param array<string, mixed> $record */
    private function writeDatabase(array $record): void
    {
        Guard::safely(
            static fn (): bool => (new AuditEntry($record))->save(),
            false,
            'AuditLogger::writeDatabase'
        );
    }

    /** @param array<string, mixed> $record */
    private function writeOffBox(array $record): void
    {
        Guard::safely(
            function () use ($record): bool {
                $line = json_encode(array_merge($record, [
                    'occurred_at' => $record['occurred_at'] instanceof Carbon
                        ? $record['occurred_at']->toIso8601String()
                        : $record['occurred_at'],
                    'product' => 'librenms-webterm',
                ]), JSON_UNESCAPED_SLASHES);

                if ($line === false) {
                    return false;
                }

                if ((bool) config('webterm.audit.syslog', true)) {
                    // openlog/syslog are always available; no extension needed.
                    openlog('librenms-webterm', LOG_PID | LOG_NDELAY, LOG_AUTHPRIV);
                    syslog($this->syslogPriority((int) $record['severity']), $line);
                    closelog();
                }

                $file = config('webterm.audit.json_file');
                if (is_string($file) && $file !== '') {
                    file_put_contents($file, $line.PHP_EOL, FILE_APPEND | LOCK_EX);
                }

                return true;
            },
            false,
            'AuditLogger::writeOffBox'
        );
    }

    private function mirrorToEventlog(Event $event, ?int $deviceId, ?string $username, ?string $reasonCode): void
    {
        if (! (bool) config('webterm.audit.mirror_to_eventlog', true) || $deviceId === null) {
            return;
        }

        $message = sprintf(
            'WebTerm: %s%s%s',
            $event->value,
            $username !== null ? ' by '.$username : '',
            $reasonCode !== null ? ' ('.$reasonCode.')' : ''
        );

        $this->eventlog->write(Sanitize::text($message), $deviceId, $event->severity());
    }

    private function usernameOf(?Authenticatable $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $name = $user->username ?? $user->name ?? null;

        return is_string($name) ? Sanitize::text($name, 64) : null;
    }

    private function sourceIp(): ?string
    {
        return Guard::safely(
            static function (): ?string {
                if (! app()->bound('request')) {
                    return null;
                }

                $ip = request()->ip();

                return is_string($ip) ? $ip : null;
            },
            null,
            'AuditLogger::sourceIp'
        );
    }

    private function syslogPriority(int $severity): int
    {
        return match (true) {
            $severity >= Severity::Error->value => LOG_ERR,
            $severity >= Severity::Warning->value => LOG_WARNING,
            $severity >= Severity::Notice->value => LOG_NOTICE,
            default => LOG_INFO,
        };
    }
}
