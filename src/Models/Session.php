<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Models;

use AdaptiveDataNetworks\WebTerm\Protocol;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $session_id
 * @property int $user_id
 * @property int $device_id
 * @property string $state
 * @property string|null $method
 * @property string|null $principal
 * @property string|null $target
 * @property string|null $gateway_instance_id
 * @property Carbon|null $started_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $ended_at
 * @property string|null $close_reason
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

    /**
     * Sessions that should count against a user's concurrency limit.
     *
     * Narrower than scopeLive() on purpose. A row is written before the SSH
     * dial is attempted, so a dial that fails leaves it PENDING; nothing in the
     * request path closes it, and the reconciler needs the gateway plus a
     * scheduler tick to notice. Counting those meant three failed connection
     * attempts locked an operator out at the default limit of three, with no
     * terminal open anywhere.
     *
     * A pending row older than the ticket TTL is provably dead: its ticket can
     * no longer be redeemed by anyone, so it can never become a session. It
     * stops occupying a slot immediately -- no scheduler, no gateway, no
     * waiting. The reconciler still closes the row for tidiness and audit.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOccupying(Builder $query): void
    {
        $deadline = Carbon::now()->subSeconds(Protocol::TICKET_TTL_SECONDS);

        $query->where(function (Builder $q) use ($deadline): void {
            $q->where('state', self::ACTIVE)
                ->orWhere(function (Builder $p) use ($deadline): void {
                    $p->where('state', self::PENDING)
                        ->where('started_at', '>', $deadline);
                });
        });
    }
}
