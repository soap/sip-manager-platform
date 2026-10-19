<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_operations', function (Blueprint $table) {
            // the operation_id handed to clients
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('aggregate_type');
            $table->unsignedBigInteger('aggregate_id');
            $table->string('idempotency_key')->nullable();
            $table->foreignId('config_revision_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['aggregate_type', 'aggregate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_operations');
    }
};
