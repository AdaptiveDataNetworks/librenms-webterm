<?php

declare(strict_types=1);

use Adn\WebTerm\Credentials\CredentialEncrypter;
use Adn\WebTerm\Credentials\CredentialMethod;
use Adn\WebTerm\Models\Ability;
use Adn\WebTerm\Models\Credential;
use Adn\WebTerm\Models\Grant;
use Adn\WebTerm\Models\HostKey;
use Adn\WebTerm\Models\Target;
use Adn\WebTerm\Tests\TestCase;

uses(TestCase::class)->in('Feature');

/**
 * A device that is fully ready to have a session minted against it: enabled
 * target, pinned host key, ability, grant and a stored credential.
 *
 * Shared because several suites start from "everything is in place" and then
 * remove exactly one thing -- which is what makes a failure point at the
 * control that stopped working.
 */
function seedMintable(): void
{
    config()->set('webterm.enabled', true);
    config()->set('webterm.audit.syslog', false);

    Target::create([
        'device_id' => 42, 'protocol' => 'ssh', 'enabled' => true,
        'flow' => 'database', 'host_key_policy' => Target::POLICY_PIN, 'principal' => 'netops',
    ]);
    HostKey::create([
        'device_id' => 42, 'algorithm' => 'ssh-ed25519', 'public_key' => 'AAAA',
        'fingerprint' => 'SHA256:x', 'status' => HostKey::PINNED,
    ]);
    Ability::create(['user_id' => 7, 'ability' => Ability::USE]);
    Grant::create([
        'subject_type' => Grant::SUBJECT_USER, 'subject_ref' => '7',
        'object_type' => Grant::OBJECT_DEVICE, 'object_id' => 42, 'effect' => Grant::ALLOW,
    ]);

    $enc = new CredentialEncrypter;
    Credential::create([
        'device_id' => 42, 'protocol' => 'ssh',
        'method' => CredentialMethod::Password->value, 'username' => 'netops',
        'payload' => $enc->encrypt(['password' => 'CANARY-mint-secret']),
        'cipher' => $enc->cipher(), 'key_id' => $enc->keyId(),
    ]);
}
