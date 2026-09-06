<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Authorization;

use AdaptiveDataNetworks\WebTerm\Auth\Totp;
use AdaptiveDataNetworks\WebTerm\Authorization\Contracts\TwoFactorSource;
use AdaptiveDataNetworks\WebTerm\Librenms\TwoFactorReader;
use AdaptiveDataNetworks\WebTerm\Models\StepUp;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;

/**
 * TOTP step-up at connect time.
 *
 * Reuses the authenticator entry the operator already registered with
 * LibreNMS, but tracks satisfaction in our own table rather than the session.
 * LibreNMS's login two-factor sets session('twofactor') once and leaves it set,
 * so a stolen cookie carries it; step-up must be independent of that or it
 * defends against nothing.
 *
 * Three bounds apply, and all three are enforced:
 *   - a rolling grace, so an operator working through a change window is not
 *     challenged on every device;
 *   - an absolute cap, so that grace cannot be renewed indefinitely;
 *   - single use per TOTP step, so an observed code cannot be replayed within
 *     its validity window.
 */
final class TotpStepUp implements StepUpGate
{
    public const MAX_FAILURES = 5;

    public const LOCKOUT_SECONDS = 300;

    public function __construct(
        private readonly TwoFactorSource $twoFactor = new TwoFactorReader,
    ) {}

    public function isRequired(): bool
    {
        return (bool) config('webterm.security.step_up', true);
    }

    public function isSatisfied(Authenticatable $user, ?Carbon $at = null): bool
    {
        $at ??= Carbon::now();
        $record = StepUp::query()->find($user->getAuthIdentifier());

        return $record !== null && $record->isSatisfiedAt($at);
    }

    /**
     * Whether this user could ever satisfy step-up.
     *
     * Used by the settings UI, which refuses to require step-up while nobody is
     * enrolled -- otherwise enabling the control locks every operator out with
     * no way back in through the UI.
     */
    public function canSatisfy(Authenticatable $user): bool
    {
        return $this->twoFactor->isEnrolled($user);
    }

    /**
     * Attempt a challenge. Returns true when step-up is now satisfied.
     */
    public function attempt(Authenticatable $user, string $code, ?Carbon $at = null): bool
    {
        $at ??= Carbon::now();
        $userId = (int) $user->getAuthIdentifier();

        $record = StepUp::query()->find($userId) ?? new StepUp(['user_id' => $userId]);

        if ($record->isLockedAt($at)) {
            return false;
        }

        $secret = $this->twoFactor->secretFor($user);
        if ($secret === null) {
            return false;
        }

        $step = Totp::verify($secret, $code, $at->getTimestamp());

        // Reject a correct code whose step has already been consumed. Without
        // this a code is replayable for its full window.
        if ($step === null || ($record->last_step !== null && $step <= $record->last_step)) {
            $this->recordFailure($record, $at);

            return false;
        }

        $grace = (int) config('webterm.security.step_up_grace_seconds', 900);
        $cap = (int) config('webterm.security.step_up_absolute_cap_seconds', 28800);

        $record->last_step = $step;
        $record->satisfied_at = $at;
        $record->expires_at = $at->copy()->addSeconds($grace);
        $record->absolute_expires_at ??= $at->copy()->addSeconds($cap);
        $record->failures = 0;
        $record->locked_until = null;
        $record->save();

        return true;
    }

    /**
     * Drop any satisfied state, e.g. on logout or an administrative revoke.
     */
    public function reset(Authenticatable $user): void
    {
        StepUp::query()->where('user_id', $user->getAuthIdentifier())->delete();
    }

    private function recordFailure(StepUp $record, Carbon $at): void
    {
        $record->failures = $record->failures + 1;

        if ($record->failures >= self::MAX_FAILURES) {
            $record->locked_until = $at->copy()->addSeconds(self::LOCKOUT_SECONDS);
            $record->failures = 0;
        }

        // A failed attempt must not extend an existing grace.
        $record->save();
    }
}
