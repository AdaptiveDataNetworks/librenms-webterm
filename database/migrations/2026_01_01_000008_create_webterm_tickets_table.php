<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Issued session tickets.
|
| Only the SHA-256 of the ticket is stored, never the ticket itself: a database
| read must not yield a usable credential for an in-flight session.
|
| The single-use guarantee is enforced by the gateway (an in-memory
| compare-and-swap, since it owns the pending session). These rows exist for
| audit and reconciliation -- they record that a ticket was minted and, when we
| learn of it, that it was redeemed.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webterm_tickets', function (Blueprint $table) {
            $table->char('session_id', 26)->primary();   // ULID
            $table->char('ticket_hash', 64);             // sha256 hex
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('device_id');
            $table->unsignedBigInteger('gateway_id')->nullable()->default(null);
            $table->string('method', 32);
            $table->string('principal', 64)->nullable()->default(null);

            $table->timestamp('issued_at')->nullable()->default(null);
            $table->timestamp('expires_at')->nullable()->default(null);
            $table->timestamp('redeemed_at')->nullable()->default(null);

            $table->index(['user_id', 'issued_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webterm_tickets');
    }
};
