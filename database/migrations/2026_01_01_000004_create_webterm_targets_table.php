<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Which devices are terminal-eligible, and how each one is reached.
|
| A device with no row here cannot be connected to at all. That is the
| default-deny posture: enabling the plugin grants nothing until an
| administrator enables specific targets.
|
| `protocol` is reserved. v1.0 only ever writes 'ssh', but the unique key on
| (device_id, protocol) means a future RDP backend is a new row rather than a
| primary-key migration on live grant history.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webterm_targets', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('device_id');
            $table->string('protocol', 16)->default('ssh');
            $table->unsignedBigInteger('gateway_id')->nullable()->default(null);
            $table->boolean('enabled')->default(false);

            // Pinned per target, never inferred from devices.os at connect time:
            // a device silently switching to a reusable-secret flow would break
            // the security claim of a Vault deployment.
            $table->string('flow', 32)->default('database');
            $table->string('host_key_policy', 32)->default('pin');
            $table->string('algorithm_profile', 16)->default('modern');

            // Server-derived SSH principal. Never accepted from the browser.
            $table->string('principal', 64)->nullable()->default(null);

            $table->timestamp('created_at')->nullable()->default(null);
            $table->timestamp('updated_at')->nullable()->default(null);

            $table->unique(['device_id', 'protocol']);
            $table->index('enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webterm_targets');
    }
};
