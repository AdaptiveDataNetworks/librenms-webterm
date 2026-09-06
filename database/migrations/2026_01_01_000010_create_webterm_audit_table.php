<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The audit trail. Append-only by policy, enforced in the model: an attempt to
| update an existing row throws.
|
| Deliberately NOT hash-chained in v1.0. A chain forks under concurrency,
| serialises the connect path on a single row lock, and breaks permanently the
| first time a retention purge removes an early row. The real tamper-evidence
| control is the off-box syslog/JSON fan-out, which is written BEFORE this row
| for denials and failures -- an attacker who compromises LibreNMS can rewrite
| this table, but not a stream that already left the host.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webterm_audit', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at')->nullable()->default(null);

            $table->string('event', 48)->index();      // closed vocabulary
            $table->unsignedTinyInteger('severity')->default(2);

            $table->unsignedInteger('user_id')->nullable()->default(null)->index();
            $table->string('username', 64)->nullable()->default(null);
            $table->unsignedInteger('device_id')->nullable()->default(null)->index();
            $table->char('session_id', 26)->nullable()->default(null)->index();

            $table->string('reason_code', 48)->nullable()->default(null);
            $table->string('source_ip', 45)->nullable()->default(null);
            $table->text('detail')->nullable()->default(null);

            $table->index(['occurred_at', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webterm_audit');
    }
};
