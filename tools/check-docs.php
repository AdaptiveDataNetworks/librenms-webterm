<?php

declare(strict_types=1);

/**
 * Enforces locally what `mkdocs build --strict` enforces in CI.
 *
 * mkdocs is a Python toolchain and not every contributor will have it, but a
 * broken link or an orphaned page should not have to wait for CI to be caught.
 * This checks the four things --strict would fail on:
 *
 *   1. every nav entry points at a file that exists
 *   2. every internal link resolves
 *   3. every page under docs/ is reachable from nav (no orphans)
 *   4. every #anchor link matches a real heading
 *
 * Anchors matter more than they look: mkdocs.yml sets validation.anchors, so a
 * link to a heading that has been reworded fails the build rather than merely
 * landing at the top of the page.
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

/**
 * Headings each page offers, slugified the way Python-Markdown's toc does.
 *
 * @var array<string, array<string, true>> $anchors
 */
$anchors = [];
foreach ($onDisk as $rel) {
    $body = (string) file_get_contents($docs.'/'.$rel);
    preg_match_all('/^#{1,6}\s+(.+?)\s*$/m', $body, $headings);

    $anchors[$rel] = [];
    foreach ($headings[1] as $heading) {
        $anchors[$rel][slugify($heading)] = true;
    }
}

foreach ($onDisk as $rel) {
    $path = $docs.'/'.$rel;
    $body = (string) file_get_contents($path);

    preg_match_all('/\]\((?!https?:\/\/|mailto:)([^)#\s]*)#([^)\s]+)\)/', $body, $fragments, PREG_SET_ORDER);

    foreach ($fragments as $match) {
        [, $target, $fragment] = $match;

        $targetRel = $target === ''
            ? $rel
            : relativeTo($docs, dirname($path).'/'.$target);

        if ($targetRel === null || ! isset($anchors[$targetRel])) {
            $errors[] = "anchor link in docs/{$rel} points at a missing page: {$target}#{$fragment}";

            continue;
        }

        if (! isset($anchors[$targetRel][$fragment])) {
            $errors[] = "anchor link in docs/{$rel} has no matching heading: {$target}#{$fragment}";
        }
    }
}

function slugify(string $heading): string
{
    $s = strtolower(trim($heading));
    $s = (string) preg_replace('/[^\p{L}\p{N}\s-]/u', '', $s);

    return (string) preg_replace('/\s+/', '-', $s);
}

function relativeTo(string $base, string $path): ?string
{
    $real = realpath($path);

    return $real === false ? null : ltrim(str_replace($base, '', $real), '/');
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
