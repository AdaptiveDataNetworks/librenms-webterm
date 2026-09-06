<?php

declare(strict_types=1);

use AdaptiveDataNetworks\WebTerm\Auth\Totp;
use AdaptiveDataNetworks\WebTerm\Support\Base32;

/*
| RFC 6238 Appendix B test vectors.
|
| A hand-written OTP implementation is only defensible if it is checked against
| the specification's own vectors. The SHA1 seed is the ASCII string
| "12345678901234567890", which we Base32-encode to feed our API.
*/

function rfcSecret(): string
{
    $raw = '12345678901234567890';
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    $bits = '';
    foreach (str_split($raw) as $ch) {
        $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
    }

    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[(int) bindec(str_pad($chunk, 5, '0'))];
    }

    return $out;
}

it('matches the RFC 6238 test vectors', function (int $time, string $expected) {
    expect(Totp::generate(rfcSecret(), Totp::stepAt($time), 8))->toBe($expected);
})->with([
    [59, '94287082'],
    [1111111109, '07081804'],
    [1111111111, '14050471'],
    [1234567890, '89005924'],
    [2000000000, '69279037'],
    [20000000000, '65353130'],
]);

it('round-trips base32 for the RFC seed', function () {
    expect(Base32::decode(rfcSecret()))->toBe('12345678901234567890');
});

it('tolerates the spaces and lower case that users paste', function () {
    $secret = rfcSecret();
    $spaced = strtolower(trim(chunk_split($secret, 4, ' ')));

    expect(Base32::decode($spaced))->toBe(Base32::decode($secret));
});

it('rejects invalid base32 rather than producing a wrong code', function () {
    expect(Base32::decode('not-valid-base32!!'))->toBeNull()
        ->and(Base32::decode(''))->toBeNull()
        ->and(Totp::generate('!!!!', 1))->toBeNull();
});

it('returns the matched step so a code can be consumed exactly once', function () {
    $secret = rfcSecret();
    $now = 1111111109;
    $code = Totp::generate($secret, Totp::stepAt($now));

    expect(Totp::verify($secret, $code, $now))->toBe(Totp::stepAt($now));
});

it('accepts adjacent steps for clock skew but not distant ones', function () {
    $secret = rfcSecret();
    $now = 1111111109;

    $previous = Totp::generate($secret, Totp::stepAt($now) - 1);
    $ancient = Totp::generate($secret, Totp::stepAt($now) - 5);

    expect(Totp::verify($secret, $previous, $now))->toBe(Totp::stepAt($now) - 1)
        ->and(Totp::verify($secret, $ancient, $now))->toBeNull();
});

it('rejects codes of the wrong length or shape', function (string $code) {
    expect(Totp::verify(rfcSecret(), $code, 1111111109))->toBeNull();
})->with([['12345'], ['1234567'], [''], ['abcdef'], ['      ']]);
