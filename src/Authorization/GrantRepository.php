<?php

declare(strict_types=1);

namespace Adn\WebTerm\Authorization;

use Adn\WebTerm\Authorization\Contracts\GroupSource;
use Adn\WebTerm\Authorization\Contracts\RoleSource;
use Adn\WebTerm\Librenms\DeviceGroups;
use Adn\WebTerm\Librenms\RoleReader;
use Adn\WebTerm\Models\Grant;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;

/**
 * Resolves the grants that apply to a (user, device) pair.
 *
 * Matching is deliberately narrow: a grant applies if its subject is this user
 * or a role this user holds, and its object is this device or a static group
 * containing it. There is no wildcard and no "all devices" object -- the
 * single most dangerous row such a schema can hold.
 */
final class GrantRepository
{
    public function __construct(
        private readonly RoleSource $roles = new RoleReader,
        private readonly GroupSource $groups = new DeviceGroups,
    ) {}

    /**
     * @param  object  $device  A LibreNMS App\Models\Device.
     * @return list<Grant>
     */
    public function matching(Authenticatable $user, object $device): array
    {
        $userId = (string) ($user->getAuthIdentifier() ?? '');
        if ($userId === '') {
            return [];
        }

        $roleNames = $this->roles->rolesOf($user);
        $deviceId = (int) ($device->device_id ?? 0);
        if ($deviceId === 0) {
            return [];
        }

        $groupIds = $this->groups->staticGroupIdsFor($device);

        $query = Grant::query()->where(function ($q) use ($userId, $roleNames): void {
            $q->where(function ($s) use ($userId): void {
                $s->where('subject_type', Grant::SUBJECT_USER)->where('subject_ref', $userId);
            });

            if ($roleNames !== []) {
                $q->orWhere(function ($s) use ($roleNames): void {
                    $s->where('subject_type', Grant::SUBJECT_ROLE)->whereIn('subject_ref', $roleNames);
                });
            }
        })->where(function ($q) use ($deviceId, $groupIds): void {
            $q->where(function ($o) use ($deviceId): void {
                $o->where('object_type', Grant::OBJECT_DEVICE)->where('object_id', $deviceId);
            });

            if ($groupIds !== []) {
                $q->orWhere(function ($o) use ($groupIds): void {
                    $o->where('object_type', Grant::OBJECT_GROUP)->whereIn('object_id', $groupIds);
                });
            }
        });

        return $query->get()->all();
    }

    /**
     * Reduce matching grants to a decision.
     *
     * Precedence, in order:
     *   1. Any active deny  -> refuse. Deny always wins, so that revoking
     *      access never requires finding every allow that might match.
     *   2. Any active allow -> permit, with limits intersected across allows.
     *   3. An allow that exists but is outside its window -> report *which*
     *      side of the window, because "expired" and "not started yet" send an
     *      administrator to different places.
     *   4. Otherwise        -> no grant.
     *
     * @param  list<Grant>  $grants
     */
    public function evaluate(array $grants, EffectiveLimits $base, Carbon $at): Decision
    {
        $activeAllows = [];
        $sawInactiveAllow = false;
        $sawFutureAllow = false;

        foreach ($grants as $grant) {
            $active = $grant->isActiveAt($at);

            if ($grant->effect === Grant::DENY) {
                // An expired deny is not a deny; a scheduled one is not yet.
                if ($active) {
                    return Decision::deny(ReasonCode::ExplicitDeny);
                }

                continue;
            }

            if ($active) {
                $activeAllows[] = $grant;

                continue;
            }

            $sawInactiveAllow = true;
            if ($grant->starts_at !== null && $at->lt($grant->starts_at)) {
                $sawFutureAllow = true;
            }
        }

        if ($activeAllows !== []) {
            $limits = $base;
            foreach ($activeAllows as $grant) {
                $limits = $limits->intersect($grant->max_duration, $grant->max_concurrent);
            }

            return Decision::allow($limits);
        }

        if ($sawInactiveAllow) {
            return Decision::deny($sawFutureAllow ? ReasonCode::GrantNotYetActive : ReasonCode::GrantExpired);
        }

        return Decision::deny(ReasonCode::NoGrant);
    }
}
