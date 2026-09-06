<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Record of an issued ticket. Only the hash is stored.
 *
 * @property string $session_id
 * @property string $ticket_hash
 */
final class Ticket extends Model
{
    protected $table = 'webterm_tickets';

    protected $primaryKey = 'session_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'session_id', 'ticket_hash', 'user_id', 'device_id', 'gateway_id',
        'method', 'principal', 'issued_at', 'expires_at', 'redeemed_at',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'redeemed_at' => 'datetime',
        ];
    }

    /**
     * Hash a ticket for storage or comparison.
     *
     * The plaintext ticket is never written anywhere: a database read must not
     * yield a usable credential for an in-flight session.
     */
    public static function hash(string $ticket): string
    {
        return hash('sha256', $ticket);
    }
}
