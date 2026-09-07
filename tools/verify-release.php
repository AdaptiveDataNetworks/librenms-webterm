<?php

declare(strict_types=1);

/**
 * Post-release verification: does every place a release lands agree on the
 * same commit?
 *
 *   php tools/verify-release.php v1.0.1
 *
 * This exists because v1.0.0 did not agree. Packagist had already published
 * the first tag; version immutability then refused the corrected re-tag; and
 * the check that should have caught it was itself wrong, comparing against
 * "1.0.0" while Packagist keys the version "v1.0.0". It reported success on a
 * mismatch.
 *
 * Uses the public REST APIs over HTTPS rather than shelling out to `gh`, so it
 * runs anywhere PHP does and needs no authentication for a public repository.
 */
const PACKAGE = 'adaptivedatanetworks/librenms-webterm';
const REPO = 'AdaptiveDataNetworks/librenms-webterm';
const EXPECTED_ASSETS = 8;

$tag = $argv[1] ?? '';
if ($tag === '') {
    fwrite(STDERR, "usage: php tools/verify-release.php <tag>   e.g. v1.0.1\n");
    exit(2);
}

$errors = [];

function fetch(string $url): ?array
{
    $body = @file_get_contents($url, false, stream_context_create([
        'http' => [
            'header' => "User-Agent: librenms-webterm-release-check\r\nAccept: application/vnd.github+json\r\n",
            'timeout' => 15,
            'ignore_errors' => true,
        ],
    ]));

    if ($body === false) {
        return null;
    }

    $decoded = json_decode($body, true);

    return is_array($decoded) ? $decoded : null;
}

/* ---- the commit the tag points at ------------------------------------- */

$tagged = trim((string) shell_exec('git rev-list -n1 '.escapeshellarg($tag).' 2>/dev/null'));
if ($tagged === '') {
    fwrite(STDERR, "No such tag locally: {$tag}\n");
    exit(1);
}
printf("  git tag %s -> %s\n", $tag, substr($tagged, 0, 12));

/* ---- what Packagist published ----------------------------------------- */

$data = fetch('https://packagist.org/packages/'.PACKAGE.'.json');
if ($data === null) {
    $errors[] = 'Could not reach Packagist.';
} else {
    $versions = $data['package']['versions'] ?? [];

    // Packagist keys tags WITH the v prefix. Normalise both sides -- comparing
    // raw strings is the exact bug this script exists to prevent.
    $wanted = ltrim($tag, 'v');
    $match = null;
    foreach ($versions as $name => $version) {
        if (ltrim((string) $name, 'v') === $wanted) {
            $match = $version;
            break;
        }
    }

    if ($match === null) {
        $errors[] = sprintf(
            'Packagist has not published %s yet (it lists: %s). Trigger an update on the package page.',
            $tag,
            implode(', ', array_keys($versions)) ?: 'nothing'
        );
    } else {
        $ref = (string) ($match['source']['reference'] ?? '');
        printf("  packagist %s -> %s\n", $tag, substr($ref, 0, 12));

        if ($ref !== $tagged) {
            $errors[] = sprintf(
                "Packagist published %s at %s but the tag points at %s.\n"
                ."       Stable versions are immutable, so re-tagging will NOT fix this.\n"
                .'       Supersede it with the next patch release.',
                $tag,
                substr($ref, 0, 12),
                substr($tagged, 0, 12)
            );
        }
    }
}

/* ---- the GitHub release ----------------------------------------------- */

$release = fetch('https://api.github.com/repos/'.REPO.'/releases/tags/'.rawurlencode($tag));
$checksumsUrl = null;

if ($release === null || ! isset($release['assets'])) {
    $errors[] = 'No GitHub release found for '.$tag.'.';
} else {
    $assets = $release['assets'];
    printf("  github release %s -> %d asset(s)\n", $tag, count($assets));

    if (count($assets) < EXPECTED_ASSETS) {
        $errors[] = sprintf('Expected at least %d release assets, found %d.', EXPECTED_ASSETS, count($assets));
    }

    foreach ($assets as $asset) {
        if (($asset['name'] ?? '') === 'checksums.txt') {
            $checksumsUrl = $asset['browser_download_url'] ?? null;
        }
    }
}

/* ---- provenance: the release notes tell people to verify it ------------ */

if ($checksumsUrl === null) {
    $errors[] = 'The release has no checksums.txt, so provenance cannot be verified.';
} else {
    $checksums = @file_get_contents($checksumsUrl, false, stream_context_create([
        'http' => ['header' => "User-Agent: librenms-webterm-release-check\r\n", 'timeout' => 30],
    ]));

    if ($checksums === false || $checksums === '') {
        $errors[] = 'Could not download checksums.txt to verify provenance.';
    } else {
        $digest = hash('sha256', $checksums);
        $att = fetch('https://api.github.com/repos/'.REPO.'/attestations/sha256:'.$digest);
        $count = is_array($att['attestations'] ?? null) ? count($att['attestations']) : 0;

        printf("  provenance attestations for checksums.txt: %d\n", $count);

        if ($count < 1) {
            $errors[] = 'No build provenance attestation. The release notes tell users to verify it.';
        }
    }
}

echo "\n";

if ($errors !== []) {
    fwrite(STDERR, "verify-release FAILED\n\n");
    foreach ($errors as $e) {
        fwrite(STDERR, "  - {$e}\n");
    }
    fwrite(STDERR, "\n");
    exit(1);
}

echo "verify-release OK: git, Packagist, the GitHub release and provenance all agree.\n";
