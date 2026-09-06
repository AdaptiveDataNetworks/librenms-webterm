<?php

declare(strict_types=1);

namespace Adn\WebTerm\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A feature-level ability held by a user.
 *
 * @property int $user_id
 * @property string $ability
 */
final class Ability extends Model
{
    public const USE = 'use';

    public const ADMIN = 'admin';

    public const AUDIT_VIEW = 'audit.view';

    /** @var list<string> */
    public const ALL = [self::USE, self::ADMIN, self::AUDIT_VIEW];

    protected $table = 'webterm_abilities';

    public $timestamps = false;

    protected $fillable = ['user_id', 'ability', 'granted_at', 'granted_by'];

    protected function casts(): array
    {
        return ['granted_at' => 'datetime'];
    }
}
