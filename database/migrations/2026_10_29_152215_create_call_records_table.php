<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // plan §7: a copy of each CDR row of the nodes, with the tenants on both ends
        Schema::create('call_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pbx_node_id')->constrained()->cascadeOnDelete();
            $table->string('uniqueid', 150);
            $table->unsignedInteger('sequence')->default(0);
            $table->string('linkedid', 150)->nullable();
            // the tenant that placed the call and the one it went to; null when the CDR does not say
            $table->foreignUlid('caller_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignUlid('callee_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            // numbers as the tenant knows them, without the tenant code
            $table->string('from_number', 80)->nullable();
            $table->string('to_number', 80)->nullable();
            // answered, no_answer, busy, failed or denied
            $table->string('status', 20);
            $table->foreignId('authorization_code_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at');
            $table->unsignedInteger('duration')->default(0);
            $table->unsignedInteger('billsec')->default(0);
            // the CDR as Asterisk wrote it
            $table->string('src', 80)->nullable();
            $table->string('dst', 80)->nullable();
            $table->string('clid', 80)->nullable();
            $table->string('channel', 80)->nullable();
            $table->string('dstchannel', 80)->nullable();
            $table->string('lastapp', 80)->nullable();
            $table->string('lastdata', 80)->nullable();
            $table->string('disposition', 45)->nullable();
            $table->string('accountcode', 80)->nullable();
            $table->string('userfield', 256)->nullable();
            $table->timestamps();

            $table->unique(['pbx_node_id', 'uniqueid', 'sequence']);
            $table->index(['caller_tenant_id', 'ended_at']);
            $table->index(['callee_tenant_id', 'ended_at']);
            $table->index('ended_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_records');
    }
};
