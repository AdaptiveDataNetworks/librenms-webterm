<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Authorization;

use AdaptiveDataNetworks\WebTerm\Authorization\Contracts\VisibilityCheck;
use AdaptiveDataNetworks\WebTerm\Librenms\DeviceTarget;
use AdaptiveDataNetworks\WebTerm\Librenms\DeviceVisibility;
use AdaptiveDataNetworks\WebTerm\Models\Ability;
use AdaptiveDataNetworks\WebTerm\Models\HostKey;
use AdaptiveDataNetworks\WebTerm\Models\Session;
use AdaptiveDataNetworks\WebTerm\Models\Target;
use AdaptiveDataNetworks\WebTerm\WebTermServiceProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

/**
 * The single authority on whether a user may open a shell on a device.
 *
 * WHY THIS IS NOT A LARAVEL GATE
 * ------------------------------
 * LibreNMS registers a Gate::before that returns true for every ability for any
 * administrator. Any ability we defined would therefore be granted to every
 * admin on every device, silently, with the code still reading as though it
 * enforced something. So there is no webterm.* ability anywhere, and this class
 * is consulted directly.
 *
 * admit() runs every check once, when a session is minted. sustain() runs the
 * subset that can change mid-session, every 15 seconds, and deliberately omits
 * concurrency (the session itself is counted) and step-up (already satisfied).
 *
 * INVARIANT, enforced by a property test: anything admit() allows,
 * DeviceVisibility::canView() also allows. Shell access is always a strict
 * subset of being able to see the device.
 */
final class ShellAuthorizer
{
    public function __construct(
        private readonly VisibilityCheck $visibility = new DeviceVisibility,
        private readonly DeviceTarget $targets = new DeviceTarget,
        private readonly ?GrantRepository $grants = null,
        private readonly ?StepUpGate $stepUp = null,
    ) {}

    /**
     * Full check, at mint time.
     *
     * @param  object  $device  A LibreNMS App\Models\Device.
     */
    public function admit(Authenticatable $user, object $device, ?Carbon $at = null): Decision
    {
        $at ??= Carbon::now();

        $base = $this->baseDecision($user, $device, $at);
        if (! $base->allowed) {
            return $base;
        }

        $target = $this->targetRow($device);
        if ($target === null) {
            return Decision::deny(ReasonCode::TargetNotEnabled);
        }

        // The gateway dials IP literals only, so a device we cannot reduce to
        // one is refused here rather than failing opaquely at connect time.
        if ($this->targets->resolve($device) === null) {
            return Decision::deny(
                ReasonCode::TargetUnresolvable,
                $this->targets->rejectionReason($device)
            );
        }

        if (($target->principal ?? '') === '') {
            return Decision::deny(ReasonCode::NoPrincipal);
        }

        if ($target->requiresPinnedHostKey() && ! $this->hasPinnedHostKey($device)) {
            return Decision::deny(ReasonCode::HostKeyNotPinned);
        }

        $limits = $base->limits ?? $this->configuredLimits();

        if ($this->liveSessionCount($user) >= $limits->maxConcurrent) {
            return Decision::deny(ReasonCode::ConcurrencyLimit);
        }

        // Resolved rather than constructed: the service provider binds the
        // TOTP gate. Constructing a placeholder here is what previously made
        // step-up unsatisfiable on every real install.
        $stepUp = $this->stepUp ?? app(StepUpGate::class);

        if ($stepUp->isRequired() && ! $stepUp->isSatisfied($user)) {
            return Decision::deny(ReasonCode::StepUpRequired);
        }

        return Decision::allow($limits);
    }

    /**
     * Cheap re-check for a session that is already running.
     *
     * Omits concurrency (this session is itself counted, so including it would
     * kill the newest session on every poll) and step-up (satisfied at mint;
     * re-challenging a live terminal every 15 seconds would be unusable).
     *
     * @param  object  $device  A LibreNMS App\Models\Device.
     */
    public function sustain(Authenticatable $user, object $device, ?Carbon $at = null): Decision
    {
        return $this->baseDecision($user, $device, $at ?? Carbon::now());
    }

    /**
     * The checks common to admit() and sustain(), in the order that avoids
     * disclosing anything to a user who cannot see the device.
     */
    private function baseDecision(Authenticatable $user, object $device, Carbon $at): Decision
    {
        if (! $this->pluginEnabled()) {
            return Decision::deny(ReasonCode::PluginDisabled);
        }

        if (! (bool) config('webterm.enabled', false)) {
            return Decision::deny(ReasonCode::KillSwitch);
        }

        // Visibility is checked before anything device-specific, so that a user
        // who cannot see a device learns nothing about whether it is
        // terminal-enabled, credentialed or pinned.
        if (! $this->visibility->canView($user, $device)) {
            return Decision::deny(ReasonCode::DeviceNotVisible);
        }

        if (! $this->hasAbility($user, Ability::USE)) {
            return Decision::deny(ReasonCode::MissingAbility);
        }

        $target = $this->targetRow($device);
        if ($target === null || ! $target->enabled) {
            return Decision::deny(ReasonCode::TargetNotEnabled);
        }

        $repository = $this->grants ?? new GrantRepository;

        return $repository->evaluate(
            $repository->matching($user, $device),
            $this->configuredLimits(),
            $at
        );
    }

    private function pluginEnabled(): bool
    {
        // When the plugin manager is absent (standalone tests, or a LibreNMS
        // predating the v2 plugin system) treat the plugin as enabled and let
        // the remaining checks decide -- they are all default-deny anyway.
        if (! app()->bound(PluginManagerInterface::class)) {
            return true;
        }

        /** @var PluginManagerInterface $manager */
        $manager = app()->make(PluginManagerInterface::class);

        return $manager->pluginEnabled(WebTermServiceProvider::PLUGIN_NAME);
    }

    private function hasAbility(Authenticatable $user, string $ability): bool
    {
        $userId = $user->getAuthIdentifier();
        if ($userId === null) {
            return false;
        }

        return Ability::query()
            ->where('user_id', $userId)
            ->where('ability', $ability)
            ->exists();
    }

    private function targetRow(object $device): ?Target
    {
        $deviceId = (int) ($device->device_id ?? 0);
        if ($deviceId === 0) {
            return null;
        }

        return Target::query()
            ->where('device_id', $deviceId)
            ->where('protocol', 'ssh')
            ->first();
    }

    private function hasPinnedHostKey(object $device): bool
    {
        return HostKey::query()
            ->where('device_id', (int) ($device->device_id ?? 0))
            ->where('status', HostKey::PINNED)
            ->exists();
    }

    private function liveSessionCount(Authenticatable $user): int
    {
        return Session::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->live()
            ->count();
    }

    private function configuredLimits(): EffectiveLimits
    {
        return new EffectiveLimits(
            (int) config('webterm.session.idle_timeout', 900),
            (int) config('webterm.session.max_duration', 14400),
            (int) config('webterm.session.max_concurrent_per_user', 3),
        );
    }
}
