<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Pinned SSH host keys.
|
| Every SSH library in common use verifies nothing by default -- phpseclib,
| Go's x/crypto/ssh and Guacamole all require the caller to supply the policy.
| So this is ours to enforce, and these rows are the trust store.
|
| A key is pinned on first connect (only when policy allows) and thereafter a
| mismatch is a hard failure requiring an administrator to re-pin deliberately.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webterm_host_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('device_id');
            $table->string('algorithm', 64);          // ssh-ed25519, rsa-sha2-512, ...
            $table->text('public_key');               // base64 blob, OpenSSH form
            $table->string('fingerprint', 128);       // SHA256:...
            $table->string('status', 16)->default('pinned'); // pinned | superseded | rejected
            $table->timestamp('first_seen_at')->nullable()->default(null);
            $table->timestamp('pinned_at')->nullable()->default(null);
            $table->unsignedInteger('pinned_by')->nullable()->default(null);

            $table->unique(['device_id', 'algorithm', 'fingerprint'], 'webterm_host_keys_unique');
            $table->index(['device_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webterm_host_keys');
    }
};
