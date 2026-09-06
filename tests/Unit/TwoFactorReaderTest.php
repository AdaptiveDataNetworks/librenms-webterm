<?php

declare(strict_types=1);

use AdaptiveDataNetworks\WebTerm\Librenms\TwoFactorReader;

/*
| A source-level regression guard.
|
| LibreNMS sets session('twofactor') once at login and leaves it set for the
| life of the session. If step-up ever consults it, a stolen session cookie
| becomes sufficient to open a shell -- which is exactly the attack step-up
| exists to interrupt.
|
| This is asserted against the source rather than behaviour because the failure
| mode is a well-intentioned future edit ("the user already did 2FA, reuse it"),
| and that edit should fail CI with an explanation attached.
*/

it('never consults the login two-factor session flag', function () {
    $source = (string) file_get_contents(
        (new ReflectionClass(TwoFactorReader::class))->getFileName()
    );

    // Strip comments so the explanatory docblock does not match itself.
    $code = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);

    // Forbidden: any route to the login-time session flag.
    expect($code)->not->toContain('session(')
        ->and($code)->not->toContain('Session::')
        ->and($code)->not->toContain('$_SESSION')
        ->and($code)->not->toContain('->session()');

    // Required: it still reads the user's TOTP enrolment, which is the whole
    // reason the class exists -- so this test cannot pass by gutting it.
    expect($code)->toContain('getPref')
        ->and($code)->toContain("'twofactor'");
});

it('fails closed when LibreNMS core is absent', function () {
    $reader = new TwoFactorReader;

    // No LibreNMS in a standalone run: unavailable, and nobody is enrolled.
    // Reporting "not enrolled" makes step-up unsatisfiable, which denies the
    // session -- the safe direction.
    expect($reader->isAvailable())->toBeFalse();
});
