<?php

declare(strict_types=1);

namespace Adn\WebTerm\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An encrypted device credential for the `database` driver.
 *
 * The payload is NOT an Eloquent encrypted cast: the cast uses APP_KEY, and
 * these rows are deliberately protected by a separate derived key with a
 * per-row key_id so that rotation is incremental. CredentialEncrypter owns
 * that, and the model stays a dumb row.
 *
 * @property int $device_id
 * @property string $method
 * @property string $username
 * @property string $payload
 * @property string $cipher
 * @property string $key_id
 * @property string|null $fingerprint
 */
final class Credential extends Model
{
    protected $table = 'webterm_credentials';

    protected $fillable = [
        'device_id', 'protocol', 'method', 'username',
        'payload', 'cipher', 'key_id', 'fingerprint', 'updated_by',
    ];
}
