<?php

declare(strict_types=1);

use Adn\WebTerm\Audit\AuditLogger;
use Adn\WebTerm\Audit\Event;
use Adn\WebTerm\Models\AuditEntry;
use Adn\WebTerm\Tests\Support\FakeUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('writes an audit record', function () {
    config()->set('webterm.audit.syslog', false);

    (new AuditLogger)->log(
        Event::SessionStarted,
        new FakeUser(7),
        deviceId: 42,
        sessionId: '01AAAAAAAAAAAAAAAAAAAAAAAA',
    );

    $entry = AuditEntry::query()->first();

    expect($entry)->not->toBeNull()
        ->and($entry->event)->toBe('session.started')
        ->and($entry->user_id)->toBe(7)
        ->and($entry->device_id)->toBe(42);
});

it('refuses to update an existing audit record', function () {
    // An audit row that can be edited is not an audit row. Corrections are
    // appended, which is also what leaves them visible.
    config()->set('webterm.audit.syslog', false);

    (new AuditLogger)->log(Event::SessionStarted, new FakeUser(7), deviceId: 42);

    $entry = AuditEntry::query()->firstOrFail();
    $entry->event = 'session.ended';

    expect(fn () => $entry->save())->toThrow(LogicException::class);
});

it('refuses to delete an audit record', function () {
    config()->set('webterm.audit.syslog', false);
    (new AuditLogger)->log(Event::SessionStarted, new FakeUser(7), deviceId: 42);

    expect(fn () => AuditEntry::query()->firstOrFail()->delete())->toThrow(LogicException::class);
});

it('sanitises attacker-influenced detail before storing it', function () {
    config()->set('webterm.audit.syslog', false);

    (new AuditLogger)->log(
        Event::SessionDenied,
        new FakeUser(7),
        deviceId: 42,
        reasonCode: 'explicit_deny',
        detail: ['banner' => "\x1B[2Jwiped\nsecond line"],
    );

    $entry = AuditEntry::query()->firstOrFail();

    expect($entry->detail)->not->toContain("\x1B")
        ->and($entry->detail)->not->toContain("\n");
});

it('never throws out of the logger, whatever the sink does', function () {
    // Audit failures must not break a connection. An unwritable JSON target is
    // the realistic case: a full disk, or a path the web user cannot write.
    config()->set('webterm.audit.syslog', false);
    config()->set('webterm.audit.json_file', '/proc/definitely/not/writable');

    expect(fn () => (new AuditLogger)->log(Event::SessionStarted, new FakeUser(7), deviceId: 1))
        ->not->toThrow(Exception::class);

    // The database record still lands even though the off-box sink failed.
    expect(AuditEntry::query()->count())->toBe(1);
});

it('classifies security-relevant events so they are written off-box first', function () {
    expect(Event::SessionDenied->isSecurityRelevant())->toBeTrue()
        ->and(Event::HostKeyChanged->isSecurityRelevant())->toBeTrue()
        ->and(Event::AuthStepUpFailed->isSecurityRelevant())->toBeTrue()
        ->and(Event::SessionStarted->isSecurityRelevant())->toBeFalse();
});
