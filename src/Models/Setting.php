<?php

declare(strict_types=1);

namespace Adn\WebTerm\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Runtime overrides for config/webterm.php.
 *
 * @property string $key
 * @property string|null $value
 */
final class Setting extends Model
{
    protected $table = 'webterm_config';

    public $timestamps = false;

    protected $fillable = ['key', 'value', 'updated_at', 'updated_by'];

    protected function casts(): array
    {
        return ['updated_at' => 'datetime'];
    }
}
