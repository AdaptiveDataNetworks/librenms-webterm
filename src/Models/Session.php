<?php

declare(strict_types=1);

namespace Adn\WebTerm\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $session_id
 * @property int $user_id
 * @property int $device_id
 * @property string $state
 */
final class Session extends Model
{
    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const CLOSED = 'closed';

    protected $table = 'webterm_sessions';

    protected $primaryKey = 'session_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'session_id', 'user_id', 'device_id', 'gateway_id', 'gateway_instance_id',
        'state', 'method', 'principal', 'target',
        'started_at', 'last_seen_at', 'ended_at', 'close_reason',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeLive(Builder $query): void
    {
        $query->whereIn('state', [self::PENDING, self::ACTIVE]);
    }
}
