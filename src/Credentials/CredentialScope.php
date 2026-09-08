<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Credentials;

/**
 * What a stored credential applies to.
 *
 * Credentials were originally per-device and nothing else, which meant a fleet
 * on one service account needed one row -- and one command -- per device.
 *
 * Precedence is most specific first: a device row beats a group row, which
 * beats the global default. That order is fixed and is not configurable,
 * because the failure mode of configurable precedence is an operator who cannot
 * predict which secret a device will use. `webterm:credentials:explain` prints
 * the decision for a given device rather than leaving it to be inferred.
 */
enum CredentialScope: string
{
    /** Applies to every device with no more specific credential. */
    case Global = 'global';

    /** Applies to the members of one LibreNMS static device group. */
    case Group = 'group';

    /** Applies to exactly one device. */
    case Device = 'device';

    /**
     * Lower sorts first. Used to order candidate rows, so the winner is
     * whichever survives at the head of the list.
     */
    public function precedence(): int
    {
        return match ($this) {
            self::Device => 0,
            self::Group => 1,
            self::Global => 2,
        };
    }

    /**
     * Whether this scope names something -- a device id or a group id.
     *
     * The global scope does not, and stores 0 rather than null: a unique key
     * treats NULLs as distinct on MySQL, MariaDB and SQLite alike, so a
     * nullable reference would permit two global credentials and leave which
     * one applies down to row order.
     */
    public function isTargeted(): bool
    {
        return $this !== self::Global;
    }

    /** The stored scope_ref for a scope that names nothing. */
    public const UNTARGETED = 0;

    public function label(?int $ref): string
    {
        return match ($this) {
            self::Global => 'the global default',
            self::Group => sprintf('device group %s', $ref ?? '?'),
            self::Device => sprintf('device %s', $ref ?? '?'),
        };
    }
}
