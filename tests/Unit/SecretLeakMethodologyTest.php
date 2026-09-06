<?php

declare(strict_types=1);

/*
| Guards the guard.
|
| ResolvedCredentialTest asserts a canary never appears in any dump. That
| assertion is only meaningful if the technique can actually detect a leak, so
| this test demonstrates the failure it is protecting against: the closure-based
| holder that the original design called for, which looks airtight and is not.
|
| If PHP ever stops exposing closure bindings, this test fails and we can
| reconsider the design -- rather than carrying a workaround for a problem that
| no longer exists.
*/

it('demonstrates that a closure-held secret does leak, which is why we use a registry', function () {
    $canary = 'METHODOLOGY-CANARY-9f2b';

    $naive = new class($canary)
    {
        private Closure $secret;

        public function __construct(string $secret)
        {
            $this->secret = static fn (): string => $secret;
        }

        public function reveal(): string
        {
            return ($this->secret)();
        }
    };

    // Behaves correctly -- which is exactly why it looks safe.
    expect($naive->reveal())->toBe($canary);

    // PHP prints a closure's bound variables under [static].
    expect(print_r($naive, true))->toContain($canary);

    ob_start();
    var_dump($naive);
    $dump = (string) ob_get_clean();

    expect($dump)->toContain($canary);
});
