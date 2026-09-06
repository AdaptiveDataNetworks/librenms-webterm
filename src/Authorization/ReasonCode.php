<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Authorization;

/**
 * Why a shell was allowed or refused.
 *
 * A closed vocabulary, because these strings are written to the audit trail and
 * shipped off-box: an operator building an alert on 'explicit_deny' must be
 * able to rely on it not being reworded in a patch release. Each case also
 * carries the remediation an administrator needs, which is what makes
 * `webterm:why` useful rather than merely honest.
 */
enum ReasonCode: string
{
    case Allowed = 'allowed';
    case PluginDisabled = 'plugin_disabled';
    case KillSwitch = 'kill_switch';
    case TargetNotEnabled = 'target_not_enabled';
    case TargetUnresolvable = 'target_unresolvable';
    case DeviceNotVisible = 'device_not_visible';
    case MissingAbility = 'missing_ability';
    case ExplicitDeny = 'explicit_deny';
    case NoGrant = 'no_grant';
    case GrantNotYetActive = 'grant_not_yet_active';
    case GrantExpired = 'grant_expired';
    case ConcurrencyLimit = 'concurrency_limit';
    case StepUpRequired = 'step_up_required';
    case NoPrincipal = 'no_principal';
    case HostKeyNotPinned = 'host_key_not_pinned';

    public function isAllowed(): bool
    {
        return $this === self::Allowed;
    }

    /**
     * Whether the refusal is something the user can resolve themselves, as
     * opposed to needing an administrator. Drives whether the UI offers an
     * action or tells them who to ask.
     */
    public function isSelfResolvable(): bool
    {
        return $this === self::StepUpRequired || $this === self::ConcurrencyLimit;
    }

    public function message(): string
    {
        return match ($this) {
            self::Allowed => 'Allowed.',
            self::PluginDisabled => 'The WebTerm plugin is not enabled in LibreNMS.',
            self::KillSwitch => 'WebTerm is switched off globally.',
            self::TargetNotEnabled => 'This device has not been enabled for terminal access.',
            self::TargetUnresolvable => 'This device has no usable IP address for the gateway to dial.',
            self::DeviceNotVisible => 'You do not have access to this device.',
            self::MissingAbility => 'You do not hold the WebTerm "use" ability.',
            self::ExplicitDeny => 'A deny rule blocks your access to this device.',
            self::NoGrant => 'You have not been granted terminal access to this device.',
            self::GrantNotYetActive => 'Your access to this device has not started yet.',
            self::GrantExpired => 'Your access to this device has expired.',
            self::ConcurrencyLimit => 'You already have the maximum number of terminal sessions open.',
            self::StepUpRequired => 'Confirm your identity to open a terminal.',
            self::NoPrincipal => 'No SSH username is configured for this device.',
            self::HostKeyNotPinned => 'This device has no pinned SSH host key, and its policy forbids trust-on-first-use.',
        };
    }

    /**
     * The command an administrator would run to resolve this, or null when
     * there is nothing to run.
     */
    public function remediation(): ?string
    {
        return match ($this) {
            self::Allowed, self::ConcurrencyLimit, self::StepUpRequired => null,
            self::PluginDisabled => 'Enable WebTerm under Overview -> Plugins -> Plugin Admin.',
            self::KillSwitch => './lnms webterm:config set enabled true',
            self::TargetNotEnabled => './lnms webterm:target:enable --device=<device>',
            self::TargetUnresolvable => 'Set the device IP, or an IP override, in LibreNMS. The gateway does not resolve DNS.',
            self::DeviceNotVisible => 'Grant the user access to the device in LibreNMS itself.',
            self::MissingAbility => './lnms webterm:ability grant --user=<user> --ability=use',
            self::ExplicitDeny => './lnms webterm:deny --list  (then remove the matching deny grant)',
            self::NoGrant, self::GrantNotYetActive, self::GrantExpired => './lnms webterm:grant --user=<user> --device=<device>',
            self::NoPrincipal => './lnms webterm:credentials:set --device=<device> --username=<login>',
            self::HostKeyNotPinned => './lnms webterm:hostkey-scan --device=<device>',
        };
    }
}
