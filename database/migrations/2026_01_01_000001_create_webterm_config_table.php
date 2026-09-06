<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Runtime configuration that an administrator can change from the UI without a
| deploy. config/webterm.php remains the source of defaults; a row here
| overrides it.
|
| Note on timestamps throughout these migrations: every nullable timestamp is
| declared ->nullable()->default(null) explicitly. On MariaDB below 10.10 the
| first TIMESTAMP column in a table that is not explicitly nullable acquires an
| implicit DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, which would
| silently rewrite audit and ticket rows on every update.
|
| Note on foreign keys: there are none into LibreNMS core tables. Core owns its
| schema and may change it; a constraint from our tables into theirs would make
| our plugin able to block their migrations.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webterm_config', function (Blueprint $table) {
            $table->id();
            $table->string('key', 191)->unique();
            $table->text('value')->nullable()->default(null);
            $table->timestamp('updated_at')->nullable()->default(null);
            $table->unsignedInteger('updated_by')->nullable()->default(null);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webterm_config');
    }
};
