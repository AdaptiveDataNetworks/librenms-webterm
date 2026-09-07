<?php

declare(strict_types=1);

/**
 * Run the migrations against a real MySQL or MariaDB.
 *
 *   DBHOST=127.0.0.1 DBPORT=3306 php tools/migration-check.php mariadb-10.6
 *
 * The unit suite runs on SQLite, which cannot exercise the reason these
 * migrations are written the way they are: on MariaDB below 10.10 the first
 * non-nullable TIMESTAMP column in a table silently acquires
 * DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, which would rewrite
 * ticket and audit rows on every update. Every timestamp is therefore declared
 * ->nullable()->default(null), and this check proves it held.
 *
 * It also asserts a full rollback leaves nothing behind, because an operator
 * removing the plugin should not be left with orphaned tables.
 */
require __DIR__.'/../vendor/autoload.php';

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;

$driverName = $argv[1] ?? 'database';

$capsule = new Capsule;
$capsule->addConnection([
    'driver' => 'mysql',
    'host' => getenv('DBHOST') ?: '127.0.0.1',
    'port' => (int) (getenv('DBPORT') ?: 3306),
    'database' => getenv('DBNAME') ?: 'librenms',
    'username' => getenv('DBUSER') ?: 'root',
    'password' => getenv('DBPASS') ?: 'r00t',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
]);
$container = new Container;
$capsule->setEventDispatcher(new Dispatcher($container));
$capsule->setAsGlobal();
$capsule->bootEloquent();

// The migrations use the Schema facade, so give the facades a container.
$container->instance('db', $capsule->getDatabaseManager());
$container->bind('db.schema', fn () => $capsule->getConnection()->getSchemaBuilder());
Facade::setFacadeApplication($container);

$resolver = $capsule->getDatabaseManager();
$repo = new DatabaseMigrationRepository($resolver, 'migrations');
if (! $repo->repositoryExists()) {
    $repo->createRepository();
}

$migrator = new Migrator($repo, $resolver, new Filesystem, new Dispatcher($container));
$path = __DIR__.'/../database/migrations';

echo "== {$driverName}: running migrations ==\n";
$migrator->run([$path]);

echo "\n== tables created ==\n";
foreach ($capsule->getConnection()->select('SHOW TABLES') as $row) {
    $t = current((array) $row);
    if (str_starts_with((string) $t, 'webterm')) {
        echo "  {$t}\n";
    }
}

echo "\n== the MariaDB TIMESTAMP hazard: any implicit ON UPDATE / DEFAULT? ==\n";
$bad = 0;
foreach ($capsule->getConnection()->select('SHOW TABLES') as $row) {
    $t = (string) current((array) $row);
    if (! str_starts_with($t, 'webterm')) {
        continue;
    }
    foreach ($capsule->getConnection()->select("SHOW COLUMNS FROM `{$t}`") as $col) {
        $c = (array) $col;
        if (stripos((string) $c['Type'], 'timestamp') === false) {
            continue;
        }
        $extra = trim((string) ($c['Extra'] ?? ''));
        $default = $c['Default'];
        if (stripos($extra, 'on update') !== false
            || (strtoupper((string) $default) === 'CURRENT_TIMESTAMP')) {
            echo "  PROBLEM {$t}.{$c['Field']}  default=".var_export($default, true)."  extra='{$extra}'\n";
            $bad++;
        }
    }
}
echo $bad === 0
    ? "  none - every timestamp is explicitly nullable with no auto-update\n"
    : "  {$bad} column(s) acquired auto-update behaviour\n";

echo "\n== rollback ==\n";
$migrator->rollback([$path], ['step' => 100]);
$remaining = 0;
foreach ($capsule->getConnection()->select('SHOW TABLES') as $row) {
    if (str_starts_with((string) current((array) $row), 'webterm')) {
        $remaining++;
    }
}
echo $remaining === 0 ? "  clean: all webterm tables dropped\n" : "  {$remaining} table(s) left behind\n";

exit($bad === 0 && $remaining === 0 ? 0 : 1);
