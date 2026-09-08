<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Models;

use AdaptiveDataNetworks\WebTerm\Credentials\CredentialScope;
use Illuminate\Database\Eloquent\Builder;
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
 * @property CredentialScope $scope_type
 * @property int $scope_ref
 * @property string $payload
 * @property string $cipher
 * @property string $key_id
 * @property string|null $fingerprint
 */
final class Credential extends Model
{
    protected $table = 'webterm_credentials';

    protected $fillable = [
        'scope_type', 'scope_ref', 'protocol', 'method', 'username',
        'payload', 'cipher', 'key_id', 'fingerprint', 'updated_by',
    ];

    protected $casts = [
        'scope_type' => CredentialScope::class,
        'scope_ref' => 'int',
    ];

    /**
     * Candidate credentials for a device, most specific first.
     *
     * The ordering is the precedence rule, expressed once: a device row, then
     * any row for a group the device belongs to, then the global default. Group
     * ties break on the lowest group id so that a device in several groups
     * always resolves the same way rather than depending on row order.
     *
     * @param  list<int>  $groupIds
     * @return Builder<self>
     */
    public static function candidatesFor(int $deviceId, array $groupIds, string $protocol = 'ssh')
    {
        return self::query()
            ->where('protocol', $protocol)
            ->where(function ($q) use ($deviceId, $groupIds): void {
                $q->where(function ($d) use ($deviceId): void {
                    $d->where('scope_type', CredentialScope::Device->value)
                        ->where('scope_ref', $deviceId);
                })->orWhere('scope_type', CredentialScope::Global->value);

                if ($groupIds !== []) {
                    $q->orWhere(function ($g) use ($groupIds): void {
                        $g->where('scope_type', CredentialScope::Group->value)
                            ->whereIn('scope_ref', $groupIds);
                    });
                }
            })
            ->orderByRaw("case scope_type when 'device' then 0 when 'group' then 1 else 2 end")
            ->orderBy('scope_ref');
    }
}
