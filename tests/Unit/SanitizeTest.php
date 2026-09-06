<?php

declare(strict_types=1);

use Adn\WebTerm\Audit\Sanitize;

/*
| Audit records carry attacker-influenced text: hostnames, SSH banners, error
| messages from the far end. Log files are read in terminals, so an escape
| sequence that survives into a record can rewrite what an operator sees.
*/

it('strips ANSI escape sequences that would rewrite what an operator sees', function (string $input) {
    $clean = Sanitize::text($input);

    expect($clean)->not->toContain("\x1B")
        ->and($clean)->not->toContain('[2J')
        ->and($clean)->not->toContain('[1;31m');
})->with([
    "\x1B[2Jcleared the screen",
    "\x1B[1;31mDENIED looks like success\x1B[0m",
    "host\x1B[10Doverwritten",
    "\x1B]0;retitled window\x07",
    "\x1B]0;retitled\x1B\\",
]);

it('collapses newlines so one record cannot forge another', function () {
    // Without this, a hostname containing a newline can inject a whole fake
    // log line into a syslog stream.
    $clean = Sanitize::text("real-host\nJan 1 00:00:00 fake: session.started by root");

    expect($clean)->not->toContain("\n")
        ->and($clean)->toContain('real-host')
        ->and($clean)->toContain('fake');
});

it('removes control characters including bare escape and NUL', function () {
    expect(Sanitize::text("a\x00b\x07c\x1Bd\x7Fe"))->toBe('a b c d e');
});

it('preserves ordinary text and unicode', function () {
    expect(Sanitize::text('core-sw-01.example.com'))->toBe('core-sw-01.example.com')
        ->and(Sanitize::text('café — naïve'))->toBe('café — naïve');
});

it('truncates rather than letting a single record grow without bound', function () {
    $clean = Sanitize::text(str_repeat('x', 5000));

    expect(mb_strlen($clean))->toBe(Sanitize::MAX_LENGTH)
        ->and($clean)->toEndWith('…');
});

it('handles null and empty input', function () {
    expect(Sanitize::text(null))->toBe('')
        ->and(Sanitize::text(''))->toBe('');
});

it('sanitises nested detail arrays, keys included', function () {
    $clean = Sanitize::detail([
        "bad\x1B[2Jkey" => "value\x1B[31m",
        'nested' => ['inner' => "x\ny"],
        'number' => 42,
        'null' => null,
    ]);

    $keys = array_keys($clean);
    expect($keys[0])->not->toContain("\x1B")
        ->and($clean['nested']['inner'])->not->toContain("\n")
        ->and($clean['number'])->toBe(42)
        ->and($clean['null'])->toBeNull();
});
