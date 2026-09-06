<?php

declare(strict_types=1);

use Adn\WebTerm\Credentials\CredentialMethod;
use Adn\WebTerm\Credentials\ResolvedCredential;

/*
| These are blocking tests. If any of them fail, credential material can reach
| a log, a stack trace, a cache entry or a support bundle, and the plugin must
| not ship.
|
| The canary is checked against every serialisation path PHP offers, because
| the closure-based approach these replaced looked correct and leaked through
| print_r and var_dump: PHP exposes a closure's bound variables under [static].
*/

const CANARY = 'CANARY-b3f1c9d2-do-not-leak';

function credential(): ResolvedCredential
{
    return new ResolvedCredential(
        CredentialMethod::Password,
        'netops',
        ['password' => CANARY],
    );
}

it('does not leak the secret through print_r', function () {
    expect(print_r(credential(), true))->not->toContain(CANARY);
});

it('does not leak the secret through var_export', function () {
    expect(var_export(credential(), true))->not->toContain(CANARY);
});

it('does not leak the secret through var_dump', function () {
    ob_start();
    var_dump(credential());
    $dump = (string) ob_get_clean();

    expect($dump)->not->toContain(CANARY);
});

it('does not leak the secret through json_encode', function () {
    expect(json_encode(credential()))->not->toContain(CANARY);
});

it('refuses to be serialized at all', function () {
    // Serialising would put the secret into a queue payload, cache row or
    // session. Failing loudly is correct: there is no legitimate caller.
    expect(fn () => serialize(credential()))->toThrow(LogicException::class);
});

it('refuses to be cloned', function () {
    // Two objects sharing one vault entry would mean the first destructor
    // erases the survivor's secret.
    expect(fn () => clone credential())->toThrow(LogicException::class);
});

it('does not leak the secret through get_object_vars', function () {
    expect(print_r(get_object_vars(credential()), true))->not->toContain(CANARY);
});

it('does not leak the secret through a string cast', function () {
    expect((string) credential())->not->toContain(CANARY)
        ->and((string) credential())->toBe('[redacted credential]');
});

it('does not leak the secret into an exception trace', function () {
    $trace = '';
    try {
        (function (ResolvedCredential $c) {
            throw new RuntimeException('boom');
        })(credential());
    } catch (RuntimeException $e) {
        $trace = $e->getTraceAsString().print_r($e->getTrace(), true);
    }

    expect($trace)->not->toContain(CANARY);
});

it('still gives the secret to a legitimate caller', function () {
    expect(credential()->reveal())->toBe(['password' => CANARY]);
});

it('can be consumed, after which the secret is gone', function () {
    $c = credential();
    $c->consume();

    expect($c->isConsumed())->toBeTrue()
        ->and(fn () => $c->reveal())->toThrow(RuntimeException::class);
});

it('rejects a secret key that does not belong to the method', function () {
    expect(fn () => new ResolvedCredential(
        CredentialMethod::Password,
        'netops',
        ['certificate' => 'nope'],
    ))->toThrow(LogicException::class);
});

it('knows which methods carry a reusable secret', function () {
    // Drives the rule that trust-on-first-use is refused for reusable secrets.
    expect(CredentialMethod::Password->isReusable())->toBeTrue()
        ->and(CredentialMethod::PrivateKey->isReusable())->toBeTrue()
        ->and(CredentialMethod::SignedCertificate->isReusable())->toBeFalse();
});
