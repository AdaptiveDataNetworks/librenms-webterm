<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Who may open a shell on what.
|
| Subjects are a LibreNMS user or a LibreNMS role; objects are a device or a
| device group. Effect is allow or deny, and DENY ALWAYS WINS -- an
| administrator must be able to revoke access without hunting down every
| allow-grant that might match.
|
| The subject is stored as a single string reference -- a decimal user id, or a
| role NAME -- discriminated by subject_type. Role names rather than role ids
| because Spatie role ids are assigned per install: an exported or documented
| grant referring to role 3 means something different on another system, while
| 'admin' does not.
|
| Sentinel 0 rather than NULL in object_id: MySQL treats NULLs as distinct in a
| unique index, so two identical NULL-bearing grants would both be insertable.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webterm_grants', function (Blueprint $table) {
            $table->id();

            $table->string('subject_type', 16);            // 'user' | 'role'
            $table->string('subject_ref', 64);             // user id, or role name

            $table->string('object_type', 16);           // 'device' | 'group'
            $table->unsignedBigInteger('object_id')->default(0);

            $table->string('effect', 8)->default('allow'); // 'allow' | 'deny'

            // Time-bounded access. Null means unbounded in that direction.
            $table->timestamp('starts_at')->nullable()->default(null);
            $table->timestamp('ends_at')->nullable()->default(null);

            $table->unsignedSmallInteger('max_concurrent')->nullable()->default(null);
            $table->unsignedInteger('max_duration')->nullable()->default(null);

            $table->string('note', 255)->nullable()->default(null);
            $table->timestamp('created_at')->nullable()->default(null);
            $table->unsignedInteger('created_by')->nullable()->default(null);

            $table->unique(
                ['subject_type', 'subject_ref', 'object_type', 'object_id', 'effect'],
                'webterm_grants_unique'
            );
            $table->index(['object_type', 'object_id']);
            $table->index(['subject_type', 'subject_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webterm_grants');
    }
};
