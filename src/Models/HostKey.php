<?php

declare(strict_types=1);

namespace Adn\WebTerm\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $device_id
 * @property string $algorithm
 * @property string $public_key
 * @property string $fingerprint
 * @property string $status
 */
final class HostKey extends Model
{
    public const PINNED = 'pinned';

    public const SUPERSEDED = 'superseded';

    public const REJECTED = 'rejected';

    protected $table = 'webterm_host_keys';

    public $timestamps = false;

    protected $fillable = [
        'device_id', 'algorithm', 'public_key', 'fingerprint', 'status',
        'first_seen_at', 'pinned_at', 'pinned_by',
    ];

    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime', 'pinned_at' => 'datetime'];
    }

    /**
     * The OpenSSH known_hosts line the gateway will verify against.
     */
    public function toKnownHostsEntry(): string
    {
        return $this->algorithm.' '.$this->public_key;
    }
}
