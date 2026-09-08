<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record WHY a device is enabled for terminal access.
 *
 * Enabling a device group materialises one target row per member device rather
 * than resolving membership at authorization time. Provenance is what makes
 * that honest: without it an operator sees a device enabled and cannot tell
 * whether somebody chose it or a group did.
 *
 * Guarded and re-runnable, like every migration here: MySQL, MariaDB and SQLite
 * have no DDL transactions, so an interrupted run is never recorded and starts
 * again from the top.
 */
return new class extends Migration
{
    private const TABLE = 'webterm_targets';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! Schema::hasColumn(self::TABLE, 'source')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                // 'manual' or 'group'. Existing rows were all chosen by hand.
                $table->string('source', 16)->default('manual')->after('protocol');
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'source_ref')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                // The device group id when source is 'group', else 0. Zero
                // rather than null for the same reason the credential scope
                // uses it: null is awkward to compare and index consistently.
                $table->unsignedInteger('source_ref')->default(0)->after('source');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach (['source_ref', 'source'] as $column) {
            if (Schema::hasColumn(self::TABLE, $column)) {
                Schema::table(self::TABLE, function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
