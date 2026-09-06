<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $user_id
 * @property int|null $last_step
 * @property Carbon|null $satisfied_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $absolute_expires_at
 * @property int $failures
 * @property Carbon|null $locked_until
 */
final class StepUp extends Model
{
    protected $table = 'webterm_stepups';

    protected $primaryKey = 'user_id';

    protected $keyType = 'int';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'last_step', 'satisfied_at', 'expires_at',
        'absolute_expires_at', 'failures', 'locked_until',
    ];

    protected function casts(): array
    {
        return [
            'last_step' => 'integer',
            'failures' => 'integer',
            'satisfied_at' => 'datetime',
            'expires_at' => 'datetime',
            'absolute_expires_at' => 'datetime',
            'locked_until' => 'datetime',
        ];
    }

    public function isSatisfiedAt(Carbon $at): bool
    {
        if ($this->expires_at === null || $at->gte($this->expires_at)) {
            return false;
        }

        // The absolute cap wins even when the rolling grace has not lapsed.
        return ! ($this->absolute_expires_at !== null && $at->gte($this->absolute_expires_at));
    }

    public function isLockedAt(Carbon $at): bool
    {
        return $this->locked_until !== null && $at->lt($this->locked_until);
    }
}
