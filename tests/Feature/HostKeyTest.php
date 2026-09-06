<?php

declare(strict_types=1);

use Adn\WebTerm\Credentials\CredentialMethod;
use Adn\WebTerm\HostKeys\HostKeyManager;
use Adn\WebTerm\Models\AuditEntry;
use Adn\WebTerm\Models\HostKey;
use Adn\WebTerm\Models\Target;
use Adn\WebTerm\Tests\Support\ScanningGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const KEY_A = ['algorithm' => 'ssh-ed25519', 'public_key' => 'ssh-ed25519 AAAAaaaa comment', 'fingerprint' => 'SHA256:aaa'];
const KEY_B = ['algorithm' => 'ssh-ed25519', 'public_key' => 'ssh-ed25519 BBBBbbbb comment', 'fingerprint' => 'SHA256:bbb'];

beforeEach(function () {
    config()->set('webterm.audit.syslog', false);
});

it('pins a scanned key', function () {
    $manager = new HostKeyManager(new ScanningGateway([KEY_A]));
    $manager->pin(42, KEY_A);

    $pinned = HostKey::query()->firstOrFail();

    expect($pinned->fingerprint)->toBe('SHA256:aaa')
        ->and($pinned->status)->toBe(HostKey::PINNED)
        // The comment field is attacker-controlled text; only the blob is kept.
        ->and($pinned->public_key)->toBe('AAAAaaaa');
});

it('supersedes rather than deletes a changed key, and audits it', function () {
    // An operator reviewing a key change needs to see what it was before.
    $manager = new HostKeyManager(new ScanningGateway([KEY_A]));
    $manager->pin(42, KEY_A);
    $manager->pin(42, KEY_B);

    expect(HostKey::query()->where('fingerprint', 'SHA256:aaa')->firstOrFail()->status)
        ->toBe(HostKey::SUPERSEDED)
        ->and(HostKey::query()->where('fingerprint', 'SHA256:bbb')->firstOrFail()->status)
        ->toBe(HostKey::PINNED)
        ->and(AuditEntry::query()->where('event', 'hostkey.changed')->count())->toBe(1);
});

it('pins on first connect only when the credential is not reusable', function (string $flow, bool $expectPin) {
    $target = Target::create([
        'device_id' => 42, 'protocol' => 'ssh', 'enabled' => true,
        'flow' => $flow, 'host_key_policy' => Target::POLICY_TOFU, 'principal' => 'netops',
    ]);

    $method = $flow === 'ssh_signer' ? CredentialMethod::SignedCertificate : CredentialMethod::Password;
    $manager = new HostKeyManager(new ScanningGateway([KEY_A]));

    $manager->maybePinOnFirstConnect($target, '10.0.0.1', 22, $method);

    expect(HostKey::query()->count())->toBe($expectPin ? 1 : 0);
})->with([
    // A certificate bounds the exposure to its TTL, so first-connect trust is
    // a defensible trade. A password handed to an impostor is not.
    'certificate' => ['ssh_signer', true],
    'password' => ['database', false],
]);

it('does not re-pin a device that already has a pinned key', function () {
    $target = Target::create([
        'device_id' => 42, 'protocol' => 'ssh', 'enabled' => true,
        'flow' => 'ssh_signer', 'host_key_policy' => Target::POLICY_TOFU, 'principal' => 'netops',
    ]);

    $manager = new HostKeyManager(new ScanningGateway([KEY_B]));
    $manager->pin(42, KEY_A);
    $manager->maybePinOnFirstConnect($target, '10.0.0.1', 22, CredentialMethod::SignedCertificate);

    expect(HostKey::query()->where('status', HostKey::PINNED)->count())->toBe(1)
        ->and(HostKey::query()->where('status', HostKey::PINNED)->firstOrFail()->fingerprint)
        ->toBe('SHA256:aaa');
});

it('never pins on first connect when the policy demands a pin', function () {
    $target = Target::create([
        'device_id' => 42, 'protocol' => 'ssh', 'enabled' => true,
        'flow' => 'ssh_signer', 'host_key_policy' => Target::POLICY_PIN, 'principal' => 'netops',
    ]);

    (new HostKeyManager(new ScanningGateway([KEY_A])))
        ->maybePinOnFirstConnect($target, '10.0.0.1', 22, CredentialMethod::SignedCertificate);

    expect(HostKey::query()->count())->toBe(0);
});

it('records a reason when a pin is cleared', function () {
    // Clearing a pin is exactly what an attacker would want done after
    // substituting a device, so who and why is the control.
    $manager = new HostKeyManager(new ScanningGateway([KEY_A]));
    $manager->pin(42, KEY_A);

    expect($manager->reset(42, 'device replaced during maintenance'))->toBe(1)
        ->and($manager->hasPin(42))->toBeFalse();

    $entry = AuditEntry::query()->where('event', 'hostkey.rejected')->firstOrFail();
    expect($entry->detail)->toContain('device replaced');
});

it('requires a reason on the reset command', function () {
    $this->artisan('webterm:hostkey-reset', ['--device' => 42])
        ->expectsOutputToContain('--reason is required')
        ->assertExitCode(1);
});
