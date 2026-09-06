<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * An audit record. Append-only, enforced here rather than by convention.
 *
 * Eloquent makes updating a loaded model the most natural thing in the world,
 * so the guard is in save(): an audit row that can be edited is not an audit
 * row. Correcting a mistake means appending a new record, which is also what
 * leaves the correction visible.
 *
 * @property string $event
 * @property int $severity
 * @property int|null $user_id
 * @property string|null $username
 * @property int|null $device_id
 * @property string|null $session_id
 * @property string|null $reason_code
 * @property string|null $source_ip
 * @property string|null $detail
 */
final class AuditEntry extends Model
{
    protected $table = 'webterm_audit';

    public $timestamps = false;

    protected $fillable = [
        'occurred_at', 'event', 'severity', 'user_id', 'username',
        'device_id', 'session_id', 'reason_code', 'source_ip', 'detail',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'severity' => 'integer'];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException(
                'webterm_audit is append-only; append a correcting record instead of editing.'
            );
        }

        return parent::save($options);
    }

    public function delete(): bool
    {
        throw new LogicException('webterm_audit is append-only; use the retention command to prune.');
    }
}
