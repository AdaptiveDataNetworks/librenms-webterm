<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give credentials a scope, so one secret can serve a fleet.
 *
 * Every step is guarded and the whole migration is re-runnable. That is not
 * defensive habit -- it is required. Laravel wraps a migration in a transaction
 * only when the grammar reports supportsSchemaTransactions(), which is true for
 * PostgreSQL and SQL Server and false for MySQL, MariaDB and SQLite. Every
 * database this plugin supports therefore runs these statements one at a time,
 * with no rollback.
 *
 * This is also the first migration here to do more than one thing. If a
 * mid-sequence ALTER is interrupted -- a killed process, a lock_wait_timeout on
 * a large table -- the migration is never recorded, so the next run starts
 * again from the top. Without these guards that second run would die on a
 * column it had already added, and the only way out would be hand-written SQL
 * against the table holding encrypted credentials.
 */
return new class extends Migration
{
    private const TABLE = 'webterm_credentials';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! Schema::hasColumn(self::TABLE, 'scope_type')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->string('scope_type', 16)->default('device')->after('id');
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'scope_ref')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                // Zero, not null, for the global scope. A unique key treats
                // NULLs as distinct on every database here, so a nullable
                // column would happily accept two global credentials and make
                // resolution depend on row order. LibreNMS device and group ids
                // start at 1, so 0 is unambiguous.
                $table->unsignedInteger('scope_ref')->default(0)->after('scope_type');
            });
        }

        // Backfill before the old column goes. Restricted to rows that have not
        // been converted, so a re-run cannot overwrite a scope set by hand.
        if (Schema::hasColumn(self::TABLE, 'device_id')) {
            DB::table(self::TABLE)
                ->where('scope_ref', 0)
                ->update([
                    'scope_type' => 'device',
                    'scope_ref' => DB::raw('device_id'),
                ]);
        }

        // The old key made one credential per device the only possibility; the
        // new one makes it one per scope.
        $this->dropIndexIfExists('webterm_credentials_device_id_protocol_unique');

        if (! $this->indexExists('webterm_credentials_scope_type_scope_ref_protocol_unique')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(['scope_type', 'scope_ref', 'protocol']);
            });
        }

        // Dropped last: until this point every step above is reversible by
        // re-reading device_id, which is what makes a partial run recoverable.
        if (Schema::hasColumn(self::TABLE, 'device_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropColumn('device_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! Schema::hasColumn(self::TABLE, 'device_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unsignedInteger('device_id')->nullable()->default(null)->after('id');
            });
        }

        // Only device-scoped rows have a home in the old shape. Group and
        // global rows cannot be represented, so they are dropped rather than
        // silently rewritten into device rows that would apply to the wrong
        // equipment.
        DB::table(self::TABLE)->whereIn('scope_type', ['group', 'global'])->delete();
        DB::table(self::TABLE)->update(['device_id' => DB::raw('scope_ref')]);

        $this->dropIndexIfExists('webterm_credentials_scope_type_scope_ref_protocol_unique');

        if (! $this->indexExists('webterm_credentials_device_id_protocol_unique')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(['device_id', 'protocol']);
            });
        }

        foreach (['scope_ref', 'scope_type'] as $column) {
            if (Schema::hasColumn(self::TABLE, $column)) {
                Schema::table(self::TABLE, function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }

    private function indexExists(string $name): bool
    {
        foreach (Schema::getIndexes(self::TABLE) as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }

    private function dropIndexIfExists(string $name): void
    {
        if (! $this->indexExists($name)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($name): void {
            $table->dropUnique($name);
        });
    }
};
