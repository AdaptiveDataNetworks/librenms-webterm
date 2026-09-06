<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Live and historical terminal sessions.
|
| The gateway is the authority on what is actually running; these rows are
| LibreNMS's view, reconciled every 15 seconds. `gateway_instance_id` lets the
| reconciler detect a gateway restart and reap sessions that can no longer
| exist, rather than leaving them to occupy a user's concurrency budget.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webterm_sessions', function (Blueprint $table) {
            $table->char('session_id', 26)->primary();   // ULID, matches the ticket
            $table->unsignedInteger('user_id')->index();
            $table->unsignedInteger('device_id')->index();
            $table->unsignedBigInteger('gateway_id')->nullable()->default(null);
            $table->string('gateway_instance_id', 64)->nullable()->default(null);

            $table->string('state', 16)->default('pending'); // pending|active|closed
            $table->string('method', 32);
            $table->string('principal', 64)->nullable()->default(null);
            $table->string('target', 64)->nullable()->default(null);

            $table->timestamp('started_at')->nullable()->default(null);
            $table->timestamp('last_seen_at')->nullable()->default(null);
            $table->timestamp('ended_at')->nullable()->default(null);
            $table->string('close_reason', 64)->nullable()->default(null);

            $table->index(['state', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webterm_sessions');
    }
};
