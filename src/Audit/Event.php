<?php

declare(strict_types=1);

namespace Adn\WebTerm\Audit;

/**
 * The closed vocabulary of audit events.
 *
 * Closed because these strings leave the host in a syslog stream and operators
 * build alerts on them. Renaming 'session.denied' in a patch release would
 * silently break someone's detection rule, so new situations get new cases
 * rather than reworded ones.
 */
enum Event: string
{
    case SessionRequested = 'session.requested';
    case SessionDenied = 'session.denied';
    case SessionStarted = 'session.started';
    case SessionEnded = 'session.ended';
    case SessionKilled = 'session.killed';
    case SessionRevoked = 'session.revoked';

    case AuthStepUpChallenged = 'auth.stepup.challenged';
    case AuthStepUpFailed = 'auth.stepup.failed';
    case AuthStepUpSatisfied = 'auth.stepup.satisfied';
    case AuthStepUpLocked = 'auth.stepup.locked';

    case CredentialResolved = 'credential.resolved';
    case CredentialFailed = 'credential.failed';

    case HostKeyPinned = 'hostkey.pinned';
    case HostKeyChanged = 'hostkey.changed';
    case HostKeyRejected = 'hostkey.rejected';

    case GrantCreated = 'grant.created';
    case GrantRemoved = 'grant.removed';
    case ConfigChanged = 'config.changed';
    case GatewayUnreachable = 'gateway.unreachable';

    public function severity(): Severity
    {
        return match ($this) {
            self::SessionDenied, self::AuthStepUpFailed, self::CredentialFailed,
            self::GatewayUnreachable => Severity::Warning,

            self::HostKeyChanged, self::HostKeyRejected, self::AuthStepUpLocked,
            self::SessionRevoked => Severity::Error,

            self::SessionStarted, self::SessionKilled, self::GrantCreated,
            self::GrantRemoved, self::ConfigChanged, self::HostKeyPinned => Severity::Notice,

            default => Severity::Info,
        };
    }

    /**
     * Events that must reach the off-box stream before the database write.
     *
     * An attacker who compromises LibreNMS can rewrite our audit table; they
     * cannot recall a syslog datagram that has already left the host. So for
     * the events that matter during an intrusion, the remote write goes first.
     */
    public function isSecurityRelevant(): bool
    {
        return match ($this) {
            self::SessionDenied, self::AuthStepUpFailed, self::AuthStepUpLocked,
            self::CredentialFailed, self::HostKeyChanged, self::HostKeyRejected,
            self::SessionRevoked => true,
            default => false,
        };
    }
}
