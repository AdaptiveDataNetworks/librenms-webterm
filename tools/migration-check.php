<?php

declare(strict_types=1);

/**
 * Run the migrations against a real MySQL or MariaDB.
 *
 *   DBHOST=127.0.0.1 DBPORT=3306 php tools/migration-check.php mariadb-10.6
 *   php tools/migration-check.php mariadb-10.6 --port=33306
 *
 * Connection settings come from the environment (which is how CI passes them)
 * or from --host/--port/--database/--username/--password flags, which is how
 * you point it at a throwaway container on a machine whose 3306 is taken.
 *
 * The unit suite runs on SQLite, which cannot exercise the reason these
 * migrations are written the way they are: on MariaDB below 10.10 the first
 * non-nullable TIMESTAMP column in a table silently acquires
 * DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, which would rewrite
 * ticket and audit rows on every update. Every timestamp is therefore declared
 * ->nullable()->default(null), and this check proves it held.
 *
 * It also asserts a full rollback leaves nothing behind, because an operator
 * removing the plugin should not be left with orphaned tables, and that our
 * migrations stay out of core's `migrations` table -- which is what makes
 * LibreNMS's ./validate.php report "extra migrations".
 */
require __DIR__.'/../vendor/autoload.php';

use AdaptiveDataNetworks\WebTerm\Database\MigrationRunner;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;

$driverName = $argv[1] ?? 'database';

$flags = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $arg, $m) === 1) {
        $flags[$m[1]] = $m[2];
    }
}

/**
 * Flag, then environment, then default.
 */
$setting = static function (string $flag, string $env, string $default) use ($flags): string {
    return $flags[$flag] ?? (getenv($env) === false ? $default : (string) getenv($env));
};

$capsule = new Capsule;
$capsule->addConnection([
    'driver' => 'mysql',
    'host' => $setting('host', 'DBHOST', '127.0.0.1'),
    'port' => (int) $setting('port', 'DBPORT', '3306'),
    'database' => $setting('database', 'DBNAME', 'librenms'),
    'username' => $setting('username', 'DBUSER', 'root'),
    'password' => $setting('password', 'DBPASS', 'r00t'),
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
$connection = $capsule->getConnection();

// Stand in for core's migrations table, so we can prove we leave it alone.
$connection->statement('DROP TABLE IF EXISTS `migrations`');
$connection->statement('CREATE TABLE `migrations` (`id` int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `migration` varchar(255) NOT NULL, `batch` int NOT NULL)');

$runner = new MigrationRunner($capsule->getDatabaseManager(), new Filesystem);

echo "== {$driverName}: running migrations ==\n";
$runner->migrate();

$leakedIntoCore = (int) $connection->table('migrations')->count();
echo "\n== core's migrations table untouched? ==\n";
echo $leakedIntoCore === 0
    ? "  yes - 0 rows, so ./validate.php reports no extra migrations\n"
    : "  NO - {$leakedIntoCore} row(s) leaked into core's table\n";

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

// Reproduce an install upgraded from a release that shared core's table: the
// schema is already there, and only the bookkeeping rows are in the wrong
// place. Adoption must move them without re-running a single migration --
// re-running a CREATE TABLE here would fail the whole upgrade.
echo "\n== reconciling an install upgraded from <= 1.0.6 ==\n";
$applied = $connection->table(MigrationRunner::TABLE)->pluck('migration')->all();
$connection->statement('DROP TABLE `'.MigrationRunner::TABLE.'`');
foreach ($applied as $i => $name) {
    $connection->table('migrations')->insert(['migration' => $name, 'batch' => 1]);
}
$reclaimed = $runner->adoptLegacyRows();
$stillInCore = (int) $connection->table('migrations')->count();
$nowOurs = $runner->repositoryExists() ? (int) $connection->table(MigrationRunner::TABLE)->count() : 0;
$adopted = count($reclaimed) === count($applied) && $stillInCore === 0 && $nowOurs === count($applied);
echo $adopted
    ? '  moved '.count($reclaimed)." row(s) out of core's table, schema untouched\n"
    : '  FAILED: reclaimed='.count($reclaimed)." core={$stillInCore} ours={$nowOurs} expected=".count($applied)."\n";

// And nothing is left pending afterwards, so the listener will not try to
// re-run migrations whose tables already exist.
$pendingAfter = $runner->pending();
echo count($pendingAfter) === 0
    ? "  nothing pending afterwards\n"
    : '  FAILED: '.count($pendingAfter)." migration(s) still look pending\n";

echo "\n== rollback ==\n";
$runner->rollback();
$remaining = [];
foreach ($connection->select('SHOW TABLES') as $row) {
    $t = (string) current((array) $row);
    if (str_starts_with($t, 'webterm')) {
        $remaining[] = $t;
    }
}
echo $remaining === []
    ? "  clean: all webterm tables dropped, including the migration repository\n"
    : '  left behind: '.implode(', ', $remaining)."\n";

exit($bad === 0 && $remaining === [] && $leakedIntoCore === 0 && $adopted && $pendingAfter === [] ? 0 : 1);
