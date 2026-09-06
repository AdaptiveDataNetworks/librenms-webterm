<?php

declare(strict_types=1);

namespace Adn\WebTerm\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A device that has been made terminal-eligible.
 *
 * Absence of a row means "not connectable" -- this is where default-deny lives.
 *
 * @property int $device_id
 * @property string $protocol
 * @property bool $enabled
 * @property string $flow
 * @property string $host_key_policy
 * @property string|null $principal
 */
final class Target extends Model
{
    public const POLICY_PIN = 'pin';

    public const POLICY_TOFU = 'tofu_first_connect';

    protected $table = 'webterm_targets';

    protected $fillable = [
        'device_id', 'protocol', 'gateway_id', 'enabled',
        'flow', 'host_key_policy', 'algorithm_profile', 'principal',
    ];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    /** @param Builder<self> $query */
    public function scopeEnabled(Builder $query): void
    {
        $query->where('enabled', true);
    }

    public function requiresPinnedHostKey(): bool
    {
        return $this->host_key_policy === self::POLICY_PIN;
    }
}
