<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Librenms;

use AdaptiveDataNetworks\WebTerm\Authorization\ReasonCode;
use AdaptiveDataNetworks\WebTerm\Authorization\ShellAuthorizer;
use AdaptiveDataNetworks\WebTerm\Gateway\GatewayStatus;
use AdaptiveDataNetworks\WebTerm\Models\Target;
use AdaptiveDataNetworks\WebTerm\Support\Guard;
use Illuminate\Support\Facades\Auth;

/**
 * Everything the device tab does, minus the core interface.
 *
 * DeviceTab itself cannot be loaded without LibreNMS present -- implementing
 * LibreNMS\Interfaces\UI\DeviceTab requires the interface at class-load time --
 * so none of its logic would be reachable in the standalone suite. The
 * behaviour lives here instead and the adapter is a two-line delegation, which
 * is the same split the rest of src/Librenms uses.
 *
 * Declaring a stand-in App\Models\Device in the test process is not an
 * alternative: tests/Contract skips on class_exists(Device), so defining one
 * would silently un-skip a suite meant to run only against real core.
 */
final class DeviceTabPresenter
{
    /**
     * Whether to draw the tab link.
     *
     * Cheap on purpose: it runs for every tab on every device page load, so it
     * must not become a full authorization pass. The real decision is in
     * data() -- see DeviceTab's docblock for why that split is required rather
     * than merely tidy.
     */
    public function visibleFor(int $deviceId): bool
    {
        return Guard::safely(
            static function () use ($deviceId): bool {
                if (! (bool) config('webterm.enabled', false)) {
                    return false;
                }

                return Target::query()
                    ->where('device_id', $deviceId)
                    ->where('protocol', 'ssh')
                    ->where('enabled', true)
                    ->exists();
            },
            false,
            'DeviceTab::visible'
        );
    }

    /**
     * @param  object  $device  A LibreNMS App\Models\Device.
     * @return array<string, mixed>
     */
    public function dataFor(object $device): array
    {
        return Guard::safely(
            static function () use ($device): array {
                $user = Auth::user();

                if ($user === null) {
                    return ['webtermState' => 'denied', 'webtermReason' => __('Not signed in.')];
                }

                // Refused before the click rather than as a 409 after it.
                $mismatch = GatewayStatus::protocolMismatch();

                if ($mismatch !== null) {
                    return ['webtermState' => 'denied', 'webtermReason' => $mismatch];
                }

                // The real gate. visible() gates only the link; core reaches
                // data() without ever consulting it.
                $decision = (new ShellAuthorizer)->admit($user, $device);

                // Step-up is a prompt, not a refusal, and the tab used to get
                // that wrong: on a default install (security.step_up is true)
                // every first visit rendered "Confirm your identity to open a
                // terminal." as a dead-end denial, with no field to type the
                // code into, while the overview panel eleven lines away
                // special-cased the identical decision to ready. The terminal
                // partial already handles the 428 and reveals the form.
                if (! $decision->allowed && $decision->reason !== ReasonCode::StepUpRequired) {
                    return [
                        'webtermState' => 'denied',
                        'webtermReason' => $decision->reason->message(),
                        'webtermFix' => $decision->reason->remediation(),
                    ];
                }

                // Nothing is minted here. Opening a device page must stay free
                // of consequence; a session is created when the operator
                // clicks, not when the tab renders.
                return [
                    'webtermState' => 'ready',
                    'webtermDeviceId' => (int) ($device->device_id ?? 0),
                ];
            },
            ['webtermState' => 'denied', 'webtermReason' => __('The terminal is unavailable.')],
            'DeviceTab::data'
        );
    }
}
