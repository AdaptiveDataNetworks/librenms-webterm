<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Credentials for the `database` driver.
|
| The payload is encrypted at rest by CredentialEncrypter, whose key is either
| WEBTERM_CREDENTIAL_KEY or an HKDF derivation from APP_KEY -- deliberately not
| APP_KEY itself, so that credentials are not protected by the same key as
| session cookies.
|
| `key_id` and `cipher` are stored per row so that a rekey can proceed
| incrementally: rows on the old key remain readable while the migration runs,
| and a partially completed rekey is a recoverable state rather than an outage.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webterm_credentials', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('device_id');
            $table->string('protocol', 16)->default('ssh');

            $table->string('method', 32);        // CredentialMethod
            $table->string('username', 64);

            $table->binary('payload');           // encrypted blob
            $table->string('cipher', 32);
            $table->string('key_id', 32);        // which key encrypted this row

            $table->string('fingerprint', 128)->nullable()->default(null);

            $table->timestamp('created_at')->nullable()->default(null);
            $table->timestamp('updated_at')->nullable()->default(null);
            $table->unsignedInteger('updated_by')->nullable()->default(null);

            $table->unique(['device_id', 'protocol']);
            $table->index('key_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webterm_credentials');
    }
};
