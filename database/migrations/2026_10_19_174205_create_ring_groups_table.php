<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ring_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pbx_node_id')->constrained()->restrictOnDelete();
            // a number of the tenant, registered in dial_numbers
            $table->string('number', 10);
            $table->string('name');
            // ring_all: every member at once; sequential: one after another in position order
            $table->string('strategy')->default('ring_all');
            // the whole group (ring_all) or each member (sequential)
            $table->unsignedSmallInteger('ring_seconds')->default(20);
            // where an unanswered call goes: hangup, or an extension or ring group of the tenant
            $table->string('failover_type')->default('hangup');
            $table->unsignedBigInteger('failover_id')->nullable();
            $table->string('status')->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['failover_type', 'failover_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ring_groups');
    }
};
