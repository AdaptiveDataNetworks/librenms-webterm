<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Gateways.
|
| v1.0 supports exactly one, and there is no management UI. The table ships
| anyway, and webterm_targets carries gateway_id from the start, because
| retrofitting a gateway dimension onto live authorization and audit data later
| is materially harder than carrying an unused column now.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webterm_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->string('url', 255);
            $table->boolean('enabled')->default(true);
            $table->string('instance_id', 64)->nullable()->default(null);
            $table->unsignedSmallInteger('protocol_version')->nullable()->default(null);
            $table->timestamp('last_seen_at')->nullable()->default(null);
            $table->timestamp('created_at')->nullable()->default(null);
            $table->timestamp('updated_at')->nullable()->default(null);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webterm_gateways');
    }
};
