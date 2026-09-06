<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Feature-level abilities, held per user.
|
| These are deliberately NOT Spatie permissions and NOT Laravel Gate abilities.
| LibreNMS registers a Gate::before that returns true for every ability for any
| admin, so a Gate-based design would silently grant every administrator a shell
| on every device. Rows here are checked directly.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webterm_abilities', function (Blueprint $table) {
            $table->id();
            // No FK: LibreNMS owns the users table.
            $table->unsignedInteger('user_id')->index();
            // 'use' | 'admin' | 'audit.view'
            $table->string('ability', 32);
            $table->timestamp('granted_at')->nullable()->default(null);
            $table->unsignedInteger('granted_by')->nullable()->default(null);

            $table->unique(['user_id', 'ability']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webterm_abilities');
    }
};
