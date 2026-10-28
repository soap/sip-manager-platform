<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // every number a tenant uses on a node (extension, later ring group, queue, IVR), so no two
        // things on one node ever share a number
        Schema::create('dial_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pbx_node_id')->constrained()->restrictOnDelete();
            $table->string('number', 10);
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id');
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->unique(['owner_type', 'owner_id']);
            $table->index('pbx_node_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dial_numbers');
    }
};
