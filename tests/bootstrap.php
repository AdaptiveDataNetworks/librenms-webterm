<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

/*
| Optionally load a real LibreNMS checkout so the Contract suite can run
| against actual core classes instead of skipping.
|
|   WEBTERM_LIBRENMS_PATH=/opt/librenms vendor/bin/pest --testsuite=Contract
|
| Only class/method existence is inspected, so LibreNMS is never booted -- we
| register its autoloader after ours and let it resolve App\ and LibreNMS\
| namespaces that we do not provide.
*/
$librenms = getenv('WEBTERM_LIBRENMS_PATH');

if (is_string($librenms) && $librenms !== '') {
    $autoload = rtrim($librenms, '/').'/vendor/autoload.php';

    if (! is_file($autoload)) {
        fwrite(STDERR, "WEBTERM_LIBRENMS_PATH is set but {$autoload} does not exist.\n");
        exit(1);
    }

    require $autoload;
}
