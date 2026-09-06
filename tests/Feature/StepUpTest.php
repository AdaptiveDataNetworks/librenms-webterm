<?php

declare(strict_types=1);

use AdaptiveDataNetworks\WebTerm\Auth\Totp;
use AdaptiveDataNetworks\WebTerm\Authorization\TotpStepUp;
use AdaptiveDataNetworks\WebTerm\Models\StepUp;
use AdaptiveDataNetworks\WebTerm\Tests\Support\FakeTwoFactor;
use AdaptiveDataNetworks\WebTerm\Tests\Support\FakeUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

it('accepts a valid code and satisfies step-up for the grace period', function () {
    $now = Carbon::parse('2026-09-06 12:00:00');
    $gate = new TotpStepUp(FakeTwoFactor::enrolled(SECRET));
    $user = new FakeUser(1);

    $code = Totp::generate(SECRET, Totp::stepAt($now->getTimestamp()));

    expect($gate->attempt($user, $code, $now))->toBeTrue()
        ->and($gate->isSatisfied($user, $now))->toBeTrue()
        ->and($gate->isSatisfied($user, $now->copy()->addMinutes(10)))->toBeTrue()
        ->and($gate->isSatisfied($user, $now->copy()->addMinutes(20)))->toBeFalse();
});

it('rejects a replayed code even while it is still within its time window', function () {
    $now = Carbon::parse('2026-09-06 12:00:00');
    $gate = new TotpStepUp(FakeTwoFactor::enrolled(SECRET));
    $user = new FakeUser(1);
    $code = Totp::generate(SECRET, Totp::stepAt($now->getTimestamp()));

    expect($gate->attempt($user, $code, $now))->toBeTrue();

    // Same code, same step, five seconds later: still "valid" by TOTP rules,
    // but already consumed.
    expect($gate->attempt($user, $code, $now->copy()->addSeconds(5)))->toBeFalse();
});

it('rejects an incorrect code', function () {
    $now = Carbon::parse('2026-09-06 12:00:00');
    $gate = new TotpStepUp(FakeTwoFactor::enrolled(SECRET));

    expect($gate->attempt(new FakeUser(1), '000000', $now))->toBeFalse()
        ->and($gate->isSatisfied(new FakeUser(1), $now))->toBeFalse();
});

it('locks out after repeated failures and does not extend an existing grace', function () {
    $now = Carbon::parse('2026-09-06 12:00:00');
    $gate = new TotpStepUp(FakeTwoFactor::enrolled(SECRET));
    $user = new FakeUser(1);

    for ($i = 0; $i < TotpStepUp::MAX_FAILURES; $i++) {
        $gate->attempt($user, '000000', $now);
    }

    $record = StepUp::query()->findOrFail(1);
    expect($record->isLockedAt($now))->toBeTrue();

    // A correct code is refused while locked out.
    $code = Totp::generate(SECRET, Totp::stepAt($now->getTimestamp()));
    expect($gate->attempt($user, $code, $now))->toBeFalse();
});

it('enforces the absolute cap even when the rolling grace is renewed', function () {
    // Otherwise an operator working a long shift never re-authenticates.
    $start = Carbon::parse('2026-09-06 08:00:00');
    config()->set('webterm.security.step_up_absolute_cap_seconds', 3600);
    config()->set('webterm.security.step_up_grace_seconds', 900);

    $gate = new TotpStepUp(FakeTwoFactor::enrolled(SECRET));
    $user = new FakeUser(1);

    $gate->attempt($user, Totp::generate(SECRET, Totp::stepAt($start->getTimestamp())), $start);

    // Renew inside the cap: still satisfied.
    $mid = $start->copy()->addMinutes(30);
    expect($gate->attempt($user, Totp::generate(SECRET, Totp::stepAt($mid->getTimestamp())), $mid))->toBeTrue()
        ->and($gate->isSatisfied($user, $mid))->toBeTrue();

    // Past the absolute cap, the grace no longer counts.
    $late = $start->copy()->addMinutes(70);
    expect($gate->isSatisfied($user, $late))->toBeFalse();
});

it('cannot be satisfied by a user who is not enrolled', function () {
    $gate = new TotpStepUp(FakeTwoFactor::notEnrolled());
    $user = new FakeUser(1);

    expect($gate->canSatisfy($user))->toBeFalse()
        ->and($gate->attempt($user, '123456'))->toBeFalse();
});

it('resets satisfied state on request', function () {
    $now = Carbon::parse('2026-09-06 12:00:00');
    $gate = new TotpStepUp(FakeTwoFactor::enrolled(SECRET));
    $user = new FakeUser(1);

    $gate->attempt($user, Totp::generate(SECRET, Totp::stepAt($now->getTimestamp())), $now);
    expect($gate->isSatisfied($user, $now))->toBeTrue();

    $gate->reset($user);
    expect($gate->isSatisfied($user, $now))->toBeFalse();
});
