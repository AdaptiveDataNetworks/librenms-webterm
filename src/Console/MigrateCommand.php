<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Console;

use AdaptiveDataNetworks\WebTerm\Database\MigrationRunner;
use Illuminate\Console\Command;

/**
 * Apply this plugin's migrations, which core's `./lnms migrate` does not own.
 *
 * Normally nothing has to run this: the service provider drives the same runner
 * whenever a core migration run finishes, so `./lnms migrate` keeps our schema
 * current as a side effect. It exists for the cases where that is not enough --
 * reconciling an install upgraded from a release that shared core's migration
 * table, and undoing everything on uninstall.
 */
final class MigrateCommand extends Command
{
    protected $signature = 'webterm:migrate
        {--status : Show which migrations have run without applying anything}
        {--rollback : Roll every WebTerm migration back and drop its tables}
        {--step= : With --rollback, undo only the last N migrations instead of all of them}
        {--pretend : Print the SQL instead of running it}
        {--force : Skip the confirmation prompt when rolling back}';

    protected $description = "Apply the WebTerm plugin's database migrations";

    public function handle(MigrationRunner $runner): int
    {
        if ($this->option('status')) {
            return $this->status($runner);
        }

        return $this->option('rollback')
            ? $this->rollback($runner)
            : $this->migrate($runner);
    }

    private function migrate(MigrationRunner $runner): int
    {
        $reclaimed = $runner->adoptLegacyRows();

        if ($reclaimed !== []) {
            $this->info(sprintf(
                'Moved %d migration(s) out of the core migrations table into %s.',
                count($reclaimed),
                MigrationRunner::TABLE
            ));
            $this->line('  ./validate.php will no longer report them as extra migrations.');
        }

        $ran = $runner->migrate($this->output, (bool) $this->option('pretend'));

        if ($ran === [] && $reclaimed === []) {
            $this->info('Nothing to migrate.');
        }

        return self::SUCCESS;
    }

    private function rollback(MigrationRunner $runner): int
    {
        $steps = $this->option('step');

        if ($steps !== null && $steps !== '') {
            return $this->rollbackSteps($runner, (int) $steps);
        }

        // Every table this plugin owns goes, grants and audit history included.
        // There is no undo, so the prompt is not a formality.
        if (! $this->option('force') && ! $this->confirm(
            'This drops every WebTerm table, including stored credentials, grants and audit history. Continue?',
            false
        )) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        $rolled = $runner->rollback($this->output, (bool) $this->option('pretend'));

        $this->info(sprintf('Rolled back %d migration(s).', count($rolled)));

        return self::SUCCESS;
    }

    /**
     * Undo the last N migrations only -- the way back from a development build.
     *
     * Downgrading the plugin without this is a one-way door: released code that
     * predates a migration queries columns that migration has already changed,
     * and the full rollback above would take the audit trail with it.
     */
    private function rollbackSteps(MigrationRunner $runner, int $steps): int
    {
        if ($steps < 1) {
            $this->error('--step must be 1 or more.');

            return self::FAILURE;
        }

        $applied = $runner->ran();
        sort($applied);
        $doomed = array_slice($applied, -$steps);

        if ($doomed === []) {
            $this->info('Nothing to roll back.');

            return self::SUCCESS;
        }

        $this->line('  Will undo, most recent first:');
        foreach (array_reverse($doomed) as $migration) {
            $this->line('    '.$migration);
        }

        if (! $this->option('force') && ! $this->confirm(
            'Undo these? Any data held only in the columns they added is lost.',
            false
        )) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        $rolled = $runner->rollbackSteps($steps, $this->output, (bool) $this->option('pretend'));

        $this->info(sprintf('Rolled back %d migration(s). The rest remain applied.', count($rolled)));

        return self::SUCCESS;
    }

    private function status(MigrationRunner $runner): int
    {
        $ran = $runner->ran();
        $pending = $runner->pending();
        $legacy = $runner->legacyRows();

        $rows = [];

        foreach ([...$ran, ...$pending] as $migration) {
            $rows[$migration] = [
                $migration,
                in_array($migration, $pending, true) ? 'Pending' : 'Ran',
                in_array($migration, $legacy, true) ? 'core migrations table' : MigrationRunner::TABLE,
            ];
        }

        ksort($rows);

        $this->table(['Migration', 'Status', 'Recorded in'], array_values($rows));

        if ($legacy !== []) {
            $this->warn(sprintf(
                '%d migration(s) are still recorded in core\'s migrations table, which is what makes',
                count($legacy)
            ));
            $this->warn('./validate.php report extra migrations. Run `lnms webterm:migrate` to move them.');
        }

        return self::SUCCESS;
    }
}
