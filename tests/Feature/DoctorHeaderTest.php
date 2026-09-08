<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('reports the plugin and PHP versions in its header', function () {
    // A support exchange stalled on not knowing which version was installed:
    // a setting that silently did nothing in 1.0.2 looked exactly like one
    // that had not been run. Now the first line says.
    Artisan::call('webterm:doctor');
    $output = Artisan::output();

    expect($output)->toContain('LibreNMS WebTerm')
        ->and($output)->toContain('PHP '.PHP_VERSION);
});

it('degrades gracefully when the version cannot be determined', function () {
    // In a source checkout the package is not a dependency of itself.
    Artisan::call('webterm:doctor');

    expect(Artisan::output())->not->toContain('Exception');
});
