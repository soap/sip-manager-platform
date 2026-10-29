<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authorization_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            // whose code it is, e.g. a teacher's name; CDRs name the code by its id
            $table->string('label', 100);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // the categories it unlocks for profiles that set them to "code"
            $table->json('categories');
            // md5(tenants.auth_salt . code): all the dialplan needs; the code itself is never stored
            $table->char('node_hash', 32);
            $table->string('status')->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['tenant_id', 'node_hash']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authorization_codes');
    }
};
