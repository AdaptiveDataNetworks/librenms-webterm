<?php

declare(strict_types=1);

namespace Adn\WebTerm\Http\Controllers;

use Adn\WebTerm\Audit\AuditLogger;
use Adn\WebTerm\Audit\Event;
use Adn\WebTerm\Authorization\ReasonCode;
use Adn\WebTerm\Authorization\TotpStepUp;
use Adn\WebTerm\Gateway\GatewayException;
use Adn\WebTerm\Session\MintException;
use Adn\WebTerm\Session\SessionMinter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Mints terminal sessions.
 *
 * Everything here is deliberately terse: authorization lives in
 * ShellAuthorizer, orchestration in SessionMinter. A controller that made
 * security decisions would be a second place to keep them correct.
 */
final class SessionController
{
    public function __construct(
        private readonly SessionMinter $minter = new SessionMinter,
        private readonly TotpStepUp $stepUp = new TotpStepUp,
        private readonly AuditLogger $audit = new AuditLogger,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $device = $this->resolveDevice($request);
        $user = Auth::user();

        if ($user === null) {
            return response()->json(['message' => 'Not authenticated.'], 401);
        }

        try {
            $result = $this->minter->mint($user, $device);
        } catch (MintException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'reason' => $e->reason->value,
                'challenge' => $e->reason === ReasonCode::StepUpRequired ? 'totp' : null,
            ], $e->status())->header('Cache-Control', 'no-store');
        } catch (GatewayException $e) {
            // The gateway being down is an operational problem, not an
            // authorization one, and the message says which service to check.
            $this->audit->log(Event::GatewayUnreachable, $user, detail: ['error' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage(), 'reason' => 'gateway_unavailable'], 503);
        }

        // no-store, not merely no-cache: the ticket must not reach a shared
        // proxy cache or the browser's back-forward cache.
        return response()->json($result->toArray())->header('Cache-Control', 'no-store');
    }

    /**
     * Answer a step-up challenge.
     */
    public function stepUp(Request $request): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return response()->json(['message' => 'Not authenticated.'], 401);
        }

        $code = (string) $request->input('code', '');

        if ($this->stepUp->attempt($user, $code)) {
            $this->audit->log(Event::AuthStepUpSatisfied, $user);

            return response()->json(['satisfied' => true])->header('Cache-Control', 'no-store');
        }

        $this->audit->log(Event::AuthStepUpFailed, $user);

        return response()->json([
            'satisfied' => false,
            'message' => $this->stepUp->canSatisfy($user)
                ? 'That code was not accepted. Codes can only be used once.'
                : 'You have no authenticator enrolled in LibreNMS, so a terminal cannot be opened.',
        ], 422)->header('Cache-Control', 'no-store');
    }

    /**
     * Resolve the device from the request.
     *
     * Uses LibreNMS's own model so that route-model binding, soft deletes and
     * any future scoping behave identically to the rest of the application.
     */
    private function resolveDevice(Request $request): object
    {
        $deviceId = (int) $request->input('device_id', 0);

        $model = 'App\Models\Device';

        if (! class_exists($model)) {
            abort(500, 'LibreNMS core is not available.');
        }

        /** @var object|null $device */
        $device = $model::query()->find($deviceId);

        // A device that does not exist and a device the user cannot see must be
        // indistinguishable; ShellAuthorizer returns DeviceNotVisible for the
        // latter, which MintException maps to the same 404.
        if ($device === null) {
            abort(404);
        }

        return $device;
    }
}
