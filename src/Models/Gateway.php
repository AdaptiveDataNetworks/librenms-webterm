<?php

declare(strict_types=1);

namespace Adn\WebTerm\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $name
 * @property string $url
 * @property bool $enabled
 */
final class Gateway extends Model
{
    protected $table = 'webterm_gateways';

    protected $fillable = [
        'name', 'url', 'enabled', 'instance_id', 'protocol_version', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'protocol_version' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }
}
