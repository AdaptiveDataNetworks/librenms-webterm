<?php

declare(strict_types=1);

/**
 * Fails if composer.json declares any runtime dependency beyond the allow-list.
 *
 * WHY THIS EXISTS
 * ---------------
 * `lnms plugin:add` runs `composer require --update-no-dev` against LibreNMS's
 * OWN composer.json and composer.lock -- a live tree of ~350 packages. Every
 * runtime requirement we declare must be co-satisfiable with all of them, on
 * every LibreNMS version a user might be running. A single extra dependency can
 * make installation fail for everybody with an unreadable resolver error, and
 * can block the user's next LibreNMS update.
 *
 * So: the Vault client is built on Laravel's HTTP client (guzzle is already in
 * LibreNMS's lockfile), all SSH work lives in the Go gateway, and dev tooling
 * goes in require-dev, which --no-dev never installs.
 *
 * If you are here because you want to add a dependency: don't. If you must,
 * changing this allow-list is a deliberate, reviewed decision.
 */
const ALLOWED_REQUIRE = [
    'php',
    'librenms/plugin-interfaces',
];

const FORBIDDEN_ANYWHERE_IN_REQUIRE = [
    'laravel/framework',   // LibreNMS pins ^12.10; a constraint here can only conflict.
    'librenms/librenms',   // type=project, never a dependency.
    'illuminate/support',  // Provided by the host app; declare in suggest.
    'illuminate/contracts',
];

$root = dirname(__DIR__);
$manifest = json_decode((string) file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);

$errors = [];
$require = array_keys($manifest['require'] ?? []);

foreach ($require as $package) {
    if (! in_array($package, ALLOWED_REQUIRE, true)) {
        $errors[] = sprintf(
            'Disallowed runtime dependency "%s". Runtime require must contain only: %s. '
            .'Move it to require-dev, or reimplement on what LibreNMS already ships.',
            $package,
            implode(', ', ALLOWED_REQUIRE)
        );
    }
}

foreach (FORBIDDEN_ANYWHERE_IN_REQUIRE as $package) {
    if (array_key_exists($package, $manifest['require'] ?? [])) {
        $errors[] = sprintf('Package "%s" must never appear in require.', $package);
    }
}

foreach (ALLOWED_REQUIRE as $package) {
    if (! array_key_exists($package, $manifest['require'] ?? [])) {
        $errors[] = sprintf('Expected runtime dependency "%s" is missing from require.', $package);
    }
}

if (($manifest['type'] ?? null) !== 'library') {
    $errors[] = 'composer.json "type" must be "library".';
}

if (array_key_exists('version', $manifest)) {
    $errors[] = 'composer.json must not contain a "version" key; Packagist derives versions from git tags.';
}

if (($manifest['license'] ?? null) !== 'GPL-3.0-or-later') {
    $errors[] = 'composer.json "license" must be the SPDX identifier GPL-3.0-or-later, matching LICENSE.';
}

$providers = $manifest['extra']['laravel']['providers'] ?? [];
if ($providers === []) {
    $errors[] = 'extra.laravel.providers is empty; without it Laravel package auto-discovery '
        .'never registers the plugin and NOTHING loads.';
}

if ($errors !== []) {
    fwrite(STDERR, "composer-guard FAILED\n\n");
    foreach ($errors as $e) {
        fwrite(STDERR, "  - {$e}\n");
    }
    fwrite(STDERR, "\n");
    exit(1);
}

echo 'composer-guard OK: runtime require is ['.implode(', ', $require)."]\n";
