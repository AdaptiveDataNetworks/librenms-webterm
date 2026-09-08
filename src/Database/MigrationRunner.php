<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Database;

use Illuminate\Console\OutputStyle;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;

/**
 * Runs this plugin's migrations against a repository table of our own.
 *
 * LibreNMS validates its schema by diffing the `migrations` table against the
 * files in `database/migrations/` -- and *only* that directory:
 *
 *     Schema::getAppliedMigrations()->diff(Schema::getMigrationFiles())
 *
 * Any row naming a migration core does not ship is reported by `./validate.php`
 * as an extra migration. That check has no allow-list and no plugin awareness,
 * so a plugin recording its migrations in the shared table is flagged forever,
 * by construction. The warning is cosmetic, but it appears next to genuine
 * schema corruption, and an operator cannot tell the two apart.
 *
 * So we keep our own repository table and stay out of core's entirely. The cost
 * is that `./lnms migrate` no longer discovers our migrations on its own, which
 * the service provider compensates for by listening for the end of a core
 * migration run and driving this class from there.
 */
final class MigrationRunner
{
    /**
     * Our repository table. Deliberately prefixed like every other table we
     * own, so an operator reading SHOW TABLES sees it belongs to the plugin.
     */
    public const TABLE = 'webterm_migrations';

    /**
     * Rows in core's `migrations` table matching this are ours to reclaim.
     *
     * Matching is by name rather than by "not shipped by core" because the
     * conservative failure is to leave a row alone: an unmigrated row we fail
     * to claim costs a cosmetic warning, while claiming somebody else's row
     * would make their migration re-run. Every migration this plugin has ever
     * shipped is `<timestamp>_create_webterm_<name>_table`.
     */
    private const OWNED = '/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]*webterm[a-z0-9_]*$/';

    /**
     * @param  DatabaseManager  $resolver  a plain ConnectionResolverInterface would
     *                                     satisfy the Migrator, but this class also
     *                                     needs the schema builder to see whether its
     *                                     tables exist.
     */
    public function __construct(
        private readonly DatabaseManager $resolver,
        private readonly Filesystem $files,
    ) {}

    /**
     * The directory holding this plugin's migrations.
     */
    public function path(): string
    {
        return dirname(__DIR__, 2).'/database/migrations';
    }

    /**
     * Apply any migrations that have not run yet.
     *
     * @return string[] the migrations applied, in order
     */
    public function migrate(?OutputStyle $output = null, bool $pretend = false): array
    {
        $migrator = $this->migrator($output);

        $this->createRepository();
        $this->adoptLegacyRows();

        return $this->names($migrator->run([$this->path()], ['pretend' => $pretend]));
    }

    /**
     * Roll every one of our migrations back and drop the repository table.
     *
     * @return string[] the migrations rolled back, in order
     */
    public function rollback(?OutputStyle $output = null, bool $pretend = false): array
    {
        $migrator = $this->migrator($output);

        // Rows may still be sitting in core's table from an older release;
        // claim them first so there is something to roll back.
        $this->createRepository();
        $this->adoptLegacyRows();

        $rolled = $this->names($migrator->reset([$this->path()], $pretend));

        if (! $pretend) {
            $this->connection()->getSchemaBuilder()->dropIfExists(self::TABLE);
        }

        return $rolled;
    }

    /**
     * Migrations on disk that have not been applied.
     *
     * @return string[]
     */
    public function pending(): array
    {
        $ran = $this->ran();

        $files = array_map(
            static fn (string $file): string => basename($file, '.php'),
            $this->files->glob($this->path().'/*_*.php') ?: []
        );

        sort($files);

        return array_values(array_diff($files, $ran));
    }

    /**
     * Migrations recorded as applied, from our table and core's alike.
     *
     * Reading both is what lets `pending()` stay correct on an install that has
     * not been reconciled yet: those migrations really have run, they are just
     * recorded in the wrong place.
     *
     * @return string[]
     */
    public function ran(): array
    {
        $ours = $this->repositoryExists()
            ? $this->connection()->table(self::TABLE)->pluck('migration')->all()
            : [];

        return array_values(array_unique([...$ours, ...$this->legacyRows()]));
    }

    /**
     * Rows of ours still recorded in core's `migrations` table.
     *
     * This is what `./validate.php` reports as extra migrations, so it doubles
     * as the doctor check: empty means the warning is gone.
     *
     * @return string[]
     */
    public function legacyRows(): array
    {
        $connection = $this->connection();

        if (! $connection->getSchemaBuilder()->hasTable('migrations')) {
            return [];
        }

        return $connection->table('migrations')
            ->pluck('migration')
            ->filter(static fn ($m): bool => is_string($m) && preg_match(self::OWNED, $m) === 1)
            ->values()
            ->all();
    }

    /**
     * Move our rows out of core's `migrations` table and into ours.
     *
     * Idempotent, and safe to run against an install that has never had our
     * migrations in the shared table. The two writes are wrapped in a
     * transaction so a failure can never lose the record that a migration ran
     * -- which would re-run it, and re-running a CREATE TABLE fails the whole
     * upgrade.
     *
     * @return string[] the migrations reclaimed
     */
    public function adoptLegacyRows(): array
    {
        $legacy = $this->legacyRows();

        if ($legacy === []) {
            return [];
        }

        // On an install upgrading from a release that shared core's table, our
        // repository does not exist yet -- this is the first thing that ever
        // touches it, so it has to create it.
        $this->createRepository();

        $connection = $this->connection();

        $connection->transaction(function () use ($connection, $legacy): void {
            $existing = $connection->table(self::TABLE)->pluck('migration')->all();
            $batch = (int) $connection->table(self::TABLE)->max('batch');

            $rows = $connection->table('migrations')
                ->whereIn('migration', $legacy)
                ->get(['migration', 'batch']);

            foreach ($rows as $row) {
                if (in_array($row->migration, $existing, true)) {
                    continue;
                }

                $connection->table(self::TABLE)->insert([
                    'migration' => $row->migration,
                    // Batch numbers are only meaningful within a repository, so
                    // core's are rebased onto ours rather than copied. Keeping
                    // them relative preserves rollback grouping.
                    'batch' => $batch + (int) $row->batch,
                ]);
            }

            $connection->table('migrations')->whereIn('migration', $legacy)->delete();
        });

        return $legacy;
    }

    public function repositoryExists(): bool
    {
        return $this->connection()->getSchemaBuilder()->hasTable(self::TABLE);
    }

    /**
     * Create our repository table if it is not there yet. Idempotent.
     */
    public function createRepository(): void
    {
        $repository = new DatabaseMigrationRepository($this->resolver, self::TABLE);

        if (! $repository->repositoryExists()) {
            $repository->createRepository();
        }
    }

    private function migrator(?OutputStyle $output = null): Migrator
    {
        // The dispatcher is deliberately null. Our migrator must not emit
        // MigrationsEnded, or the service provider's listener would call us
        // straight back into an unbounded loop.
        $migrator = new Migrator(
            new DatabaseMigrationRepository($this->resolver, self::TABLE),
            $this->resolver,
            $this->files,
            null,
        );

        if ($output instanceof OutputStyle) {
            $migrator->setOutput($output);
        }

        return $migrator;
    }

    /**
     * Migrator methods return absolute file paths; everything else here speaks
     * in migration names.
     *
     * @param  mixed  $paths
     * @return string[]
     */
    private function names($paths): array
    {
        return array_map(
            static fn (string $path): string => basename($path, '.php'),
            is_array($paths) ? array_values($paths) : []
        );
    }

    private function connection(): Connection
    {
        return $this->resolver->connection();
    }
}
