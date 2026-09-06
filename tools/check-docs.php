<?php

declare(strict_types=1);

/**
 * Enforces locally what `mkdocs build --strict` enforces in CI.
 *
 * mkdocs is a Python toolchain and not every contributor will have it, but a
 * broken link or an orphaned page should not have to wait for CI to be caught.
 * This checks the three things --strict would fail on:
 *
 *   1. every nav entry points at a file that exists
 *   2. every internal link resolves
 *   3. every page under docs/ is reachable from nav (no orphans)
 */
$root = dirname(__DIR__);
$docs = $root.'/docs';
$errors = [];

$yaml = (string) file_get_contents($root.'/mkdocs.yml');
$navSection = substr($yaml, (int) strpos($yaml, "\nnav:"));

preg_match_all('/:\s*([A-Za-z0-9._\/-]+\.md)\s*$/m', $navSection, $m);
$navFiles = array_unique($m[1]);

if ($navFiles === []) {
    $errors[] = 'No nav entries found in mkdocs.yml -- is the nav: block still there?';
}

foreach ($navFiles as $rel) {
    if (! is_file($docs.'/'.$rel)) {
        $errors[] = "nav references a missing page: docs/{$rel}";
    }
}

/** @var list<string> $onDisk */
$onDisk = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($docs, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->getExtension() === 'md') {
        $onDisk[] = ltrim(str_replace($docs, '', $file->getPathname()), '/');
    }
}

foreach ($onDisk as $rel) {
    if (! in_array($rel, $navFiles, true)) {
        $errors[] = "orphaned page not in nav (mkdocs --strict fails on this): docs/{$rel}";
    }
}

foreach ($onDisk as $rel) {
    $path = $docs.'/'.$rel;
    $body = (string) file_get_contents($path);

    // Internal markdown links only: skip absolute URLs and bare anchors.
    preg_match_all('/\]\((?!https?:\/\/|#|mailto:)([^)#\s]+)(#[^)\s]*)?\)/', $body, $links);

    foreach ($links[1] as $target) {
        $resolved = realpath(dirname($path).'/'.$target);
        if ($resolved === false) {
            $errors[] = "broken link in docs/{$rel}: {$target}";
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, "check-docs FAILED\n\n");
    foreach ($errors as $e) {
        fwrite(STDERR, "  - {$e}\n");
    }
    fwrite(STDERR, "\n");
    exit(1);
}

printf("check-docs OK: %d pages, all in nav, all links resolve\n", count($onDisk));
