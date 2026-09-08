<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Http;

use AdaptiveDataNetworks\WebTerm\Authorization\ReasonCode;
use AdaptiveDataNetworks\WebTerm\Authorization\ShellAuthorizer;
use AdaptiveDataNetworks\WebTerm\Models\Target;
use AdaptiveDataNetworks\WebTerm\Support\Guard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;

/**
 * Decides what the device-overview panel shows.
 *
 * This runs on EVERY device page load, for every user, whether or not they
 * care about terminals. So it has a hard rule: no synchronous network call,
 * ever. Gateway reachability is read from a short-lived cache populated out of
 * band, because a device page that hangs for two seconds because a terminal
 * gateway is down is a worse outcome than no terminal button.
 */
class DevicePanelPresenter
{
    private const CACHE_TTL = 30;

    public function __construct(
        private readonly ShellAuthorizer $authorizer = new ShellAuthorizer,
    ) {}

    /**
     * @param  object  $device  A LibreNMS App\Models\Device.
     * @return array{state: string, message: string, device_id: int, reason: ?string}
     */
    public function present(?Authenticatable $user, object $device): array
    {
        $deviceId = (int) ($device->device_id ?? 0);

        $fallback = [
            'state' => 'unavailable',
            'message' => 'Terminal access is unavailable.',
            'device_id' => $deviceId,
            'reason' => null,
        ];

        if ($user === null) {
            return $fallback;
        }

        return Guard::safely(
            function () use ($user, $device, $deviceId): array {
                if (! (bool) config('webterm.enabled', false)) {
                    return [
                        'state' => 'disabled',
                        'message' => 'WebTerm is switched off.',
                        'device_id' => $deviceId,
                        'reason' => ReasonCode::KillSwitch->value,
                    ];
                }

                // No target row at all is the common case on most devices; say
                // nothing rather than showing a permanent "not configured"
                // panel on every device in the estate.
                $target = Target::query()
                    ->where('device_id', $deviceId)
                    ->where('protocol', 'ssh')
                    ->first();

                if ($target === null || ! $target->enabled) {
                    return [
                        'state' => 'hidden',
                        'message' => '',
                        'device_id' => $deviceId,
                        'reason' => ReasonCode::TargetNotEnabled->value,
                    ];
                }

                // Cached per user and device: admit() touches several tables,
                // and this is a page render, not a connect.
                $decision = Cache::remember(
                    sprintf('webterm:panel:%s:%d', $user->getAuthIdentifier(), $deviceId),
                    self::CACHE_TTL,
                    fn (): array => $this->decide($user, $device),
                );

                return $decision + ['device_id' => $deviceId];
            },
            $fallback,
            'DevicePanelPresenter::present'
        );
    }

    /**
     * @param  object  $device  A LibreNMS App\Models\Device.
     * @return array{state: string, message: string, reason: ?string}
     */
    private function decide(Authenticatable $user, object $device): array
    {
        $decision = $this->authorizer->admit($user, $device);

        if ($decision->allowed) {
            return ['state' => 'ready', 'message' => '', 'reason' => null];
        }

        // A user who cannot see the device gets nothing at all, not an
        // explanation -- the explanation would itself confirm the device exists.
        if ($decision->reason === ReasonCode::DeviceNotVisible) {
            return ['state' => 'hidden', 'message' => '', 'reason' => null];
        }

        // Step-up is not a refusal; it is a prompt. Showing it as "denied"
        // would send users to an administrator who has nothing to fix.
        if ($decision->reason === ReasonCode::StepUpRequired) {
            return ['state' => 'ready', 'message' => '', 'reason' => ReasonCode::StepUpRequired->value];
        }

        return [
            'state' => 'denied',
            'message' => $decision->reason->message(),
            'reason' => $decision->reason->value,
        ];
    }
}
