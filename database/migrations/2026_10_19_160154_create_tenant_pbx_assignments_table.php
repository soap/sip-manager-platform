<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_pbx_assignments', function (Blueprint $table) {
            $table->id();
            // one primary PBX per tenant in the MVP
            $table->foreignUlid('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('sip_node_id')->constrained()->restrictOnDelete();
            // the tenant's numbers on the node; ranges of one node never overlap
            $table->unsignedInteger('number_range_start');
            $table->unsignedInteger('number_range_end');
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['sip_node_id', 'number_range_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_pbx_assignments');
    }
};
