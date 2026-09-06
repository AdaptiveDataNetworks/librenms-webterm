<?php

declare(strict_types=1);

use AdaptiveDataNetworks\WebTerm\Support\Guard;

/*
| LibreNMS sets plugin_active = 0 on any Throwable escaping a hook. These tests
| pin the behaviour that prevents one unreachable device from uninstalling the
| terminal for every user.
*/

it('returns the operation result when nothing goes wrong', function () {
    expect(Guard::safely(static fn (): string => 'ok', 'fallback', 'ctx'))->toBe('ok');
});

it('swallows an exception and returns the fallback', function () {
    $result = Guard::safely(
        static fn (): string => throw new RuntimeException('gateway unreachable'),
        'fallback',
        'ctx'
    );

    expect($result)->toBe('fallback');
});

it('swallows an Error, not just an Exception', function () {
    // A TypeError from a changed LibreNMS core signature must not disable us.
    $result = Guard::safely(
        static fn (): string => throw new TypeError('core signature changed'),
        'fallback',
        'ctx'
    );

    expect($result)->toBe('fallback');
});

it('never lets a throwable escape for any throwable type', function (Throwable $thrown) {
    expect(Guard::safely(static fn () => throw $thrown, [], 'ctx'))->toBe([]);
})->with([
    fn () => new RuntimeException('boom'),
    fn () => new LogicException('boom'),
    fn () => new Error('boom'),
    fn () => new ArithmeticError('boom'),
]);
