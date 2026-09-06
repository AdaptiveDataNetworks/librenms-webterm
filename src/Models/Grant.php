<?php

declare(strict_types=1);

namespace Adn\WebTerm\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An allow or deny grant. Deny always wins.
 *
 * @property string $subject_type
 * @property string $subject_ref
 * @property string $object_type
 * @property int $object_id
 * @property string $effect
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property int|null $max_concurrent
 * @property int|null $max_duration
 */
final class Grant extends Model
{
    public const SUBJECT_USER = 'user';

    public const SUBJECT_ROLE = 'role';

    public const OBJECT_DEVICE = 'device';

    public const OBJECT_GROUP = 'group';

    public const ALLOW = 'allow';

    public const DENY = 'deny';

    protected $table = 'webterm_grants';

    public $timestamps = false;

    protected $fillable = [
        'subject_type', 'subject_ref', 'object_type', 'object_id', 'effect',
        'starts_at', 'ends_at', 'max_concurrent', 'max_duration', 'note',
        'created_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'created_at' => 'datetime',
            'max_concurrent' => 'integer',
            'max_duration' => 'integer',
        ];
    }

    /**
     * Whether the grant is in force at $at.
     *
     * Evaluated in PHP rather than SQL so that the same rule applies to grants
     * already loaded in memory, and so the boundary conditions are testable
     * without a database.
     */
    public function isActiveAt(Carbon $at): bool
    {
        if ($this->starts_at !== null && $at->lt($this->starts_at)) {
            return false;
        }

        return ! ($this->ends_at !== null && $at->gte($this->ends_at));
    }

    /** @param Builder<self> $query */
    public function scopeDenies(Builder $query): void
    {
        $query->where('effect', self::DENY);
    }
}
