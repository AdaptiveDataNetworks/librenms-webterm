<?php

declare(strict_types=1);

/**
 * A lowercase single-word translation key is a landmine in a LibreNMS plugin.
 *
 * Laravel resolves `__('device')` by looking for a translation FILE named
 * device.php -- and LibreNMS ships lang/en/device.php. The call therefore
 * returns that entire file as an array, and `{{ ... }}` hands the array to
 * htmlspecialchars(), which is fatal. The admin console shipped with exactly
 * that in its Access tab.
 *
 * It cannot be caught by rendering the view here: LibreNMS's lang files are not
 * present under Testbench, so the key falls through to the literal string and
 * everything appears fine. The only defence that works standalone is to refuse
 * the shape, because LibreNMS's lang files are lowercase filenames and we
 * cannot know which ones a future release will add.
 *
 * Phrases are safe (a filename has no spaces), and so are namespaced keys.
 */
it('uses no lowercase single-word translation keys in any view', function (): void {
    $offenders = [];

    // array_merge, NOT +. The union operator keys on index, so the first
    // glob's element 0 masks the second's -- which silently dropped
    // admin.blade.php, the one file that had the bug this guard exists for.
    $views = array_merge(
        glob(__DIR__.'/../../resources/views/*.blade.php') ?: [],
        glob(__DIR__.'/../../resources/views/**/*.blade.php') ?: [],
    );

    expect($views)->not->toBeEmpty();

    foreach ($views as $view) {
        $markup = (string) file_get_contents($view);

        // Only real markup -- a blade comment discussing the hazard is fine.
        $markup = preg_replace('/\{\{--.*?--\}\}/s', '', $markup) ?? $markup;

        preg_match_all("/__\('([^']+)'\)/", $markup, $matches);

        foreach ($matches[1] as $key) {
            if (preg_match('/^[a-z][a-z0-9_-]*$/', $key) === 1) {
                $offenders[] = basename($view).": __('".$key."')";
            }
        }
    }

    expect($offenders)->toBe([], implode(
        "\n",
        array_merge(
            ['A lowercase single-word key can resolve to a whole LibreNMS lang file and render as an array:'],
            $offenders,
            ['Use a phrase, a capitalised label, or a namespaced key.']
        )
    ));
});
