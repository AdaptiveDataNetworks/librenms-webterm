<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Step-up authentication state, held server-side.
|
| Deliberately NOT in the session. LibreNMS's own login two-factor sets a
| session flag once and leaves it set; if step-up lived there too, a stolen
| session cookie would carry step-up with it -- which is the exact attack
| step-up exists to interrupt.
|
| `last_step` is the TOTP step counter most recently consumed, which makes each
| code single-use: without it a code stays valid for its whole window, and an
| observer who sees it typed can replay it.
|
| `absolute_expires_at` caps the total time a single challenge can be extended
| for, so a long working day cannot become an indefinitely renewed grace.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webterm_stepups', function (Blueprint $table) {
            $table->unsignedInteger('user_id')->primary();
            $table->unsignedBigInteger('last_step')->nullable()->default(null);
            $table->timestamp('satisfied_at')->nullable()->default(null);
            $table->timestamp('expires_at')->nullable()->default(null);
            $table->timestamp('absolute_expires_at')->nullable()->default(null);
            $table->unsignedSmallInteger('failures')->default(0);
            $table->timestamp('locked_until')->nullable()->default(null);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webterm_stepups');
    }
};
