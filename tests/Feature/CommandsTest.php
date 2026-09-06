<?php

declare(strict_types=1);

use AdaptiveDataNetworks\WebTerm\Authorization\ReasonCode;
use AdaptiveDataNetworks\WebTerm\Models\AuditEntry;
use AdaptiveDataNetworks\WebTerm\Models\Grant;
use AdaptiveDataNetworks\WebTerm\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('webterm.audit.syslog', false);
});

it('reports every problem on a fresh install, with a fix for each', function () {
    // A fresh install is default-deny, so doctor should be a to-do list rather
    // than a clean bill of health.
    $this->artisan('webterm:doctor')
        ->expectsOutputToContain('kill switch is off')
        ->expectsOutputToContain('./lnms webterm:config set enabled true')
        ->assertExitCode(1);
});

it('stores and masks runtime configuration', function () {
    $this->artisan('webterm:config', ['action' => 'set', 'key' => 'enabled', 'value' => 'true'])
        ->assertExitCode(0);

    expect(Setting::query()->where('key', 'enabled')->firstOrFail()->value)->toBe('true');
});

it('refuses to store a secret path as runtime config', function () {
    // A database row has no owner and no mode; the secret belongs in a file.
    $this->artisan('webterm:config', [
        'action' => 'set', 'key' => 'gateway.secret_file', 'value' => '/tmp/x',
    ])
        ->expectsOutputToContain('cannot be set here')
        ->assertExitCode(1);
});

it('audits a configuration change', function () {
    $this->artisan('webterm:config', ['action' => 'set', 'key' => 'security.step_up', 'value' => 'false']);

    expect(AuditEntry::query()->where('event', 'config.changed')->count())->toBe(1);
});

it('masks anything that looks like a secret when listing config', function () {
    Setting::create(['key' => 'credentials.vault.approle.role_id', 'value' => 'SECRET-ROLE-ID']);

    $this->artisan('webterm:config', ['action' => 'list'])
        ->doesntExpectOutputToContain('SECRET-ROLE-ID')
        ->assertExitCode(0);
});

it('rejects an unknown ability rather than silently creating one', function () {
    $this->artisan('webterm:ability', ['action' => 'grant', '--user' => '1', '--ability' => 'superuser'])
        ->expectsOutputToContain('Unknown ability')
        ->assertExitCode(1);
});

it('warns when nobody holds any ability', function () {
    $this->artisan('webterm:ability', ['action' => 'list'])
        ->expectsOutputToContain('nobody can open a terminal')
        ->assertExitCode(0);
});

it('creates and removes grants by role', function () {
    $this->artisan('webterm:grant', ['--role' => 'netops', '--group' => '9'])->assertExitCode(0);

    expect(Grant::query()->count())->toBe(1)
        ->and(Grant::query()->firstOrFail()->subject_ref)->toBe('netops');

    $this->artisan('webterm:grant', ['--role' => 'netops', '--group' => '9', '--remove' => true])
        ->assertExitCode(0);

    expect(Grant::query()->count())->toBe(0);
});

it('creates a deny grant distinctly from an allow', function () {
    $this->artisan('webterm:grant', ['--role' => 'netops', '--group' => '9', '--deny' => true])
        ->assertExitCode(0);

    expect(Grant::query()->firstOrFail()->effect)->toBe(Grant::DENY);
});

it('tells the operator an allow grant is not sufficient on its own', function () {
    // The most common support question: "I granted access and it still says no."
    $this->artisan('webterm:grant', ['--role' => 'netops', '--group' => '9'])
        ->expectsOutputToContain('also needs the "use" ability')
        ->assertExitCode(0);
});

it('requires a subject and an object', function () {
    $this->artisan('webterm:grant', ['--device' => '1'])
        ->expectsOutputToContain('Provide --user or --role')
        ->assertExitCode(1);
});

it('lists no live sessions on a fresh install', function () {
    $this->artisan('webterm:sessions')
        ->expectsOutputToContain('No live sessions')
        ->assertExitCode(0);
});

it('reports every reason code as having guidance', function () {
    // Guards against a new ReasonCode being added without remediation text,
    // which is what makes webterm:why useful rather than merely honest.
    foreach (ReasonCode::cases() as $reason) {
        expect($reason->message())->not->toBeEmpty();

        if (! $reason->isAllowed() && ! $reason->isSelfResolvable()) {
            expect($reason->remediation())->not->toBeNull();
        }
    }
});
