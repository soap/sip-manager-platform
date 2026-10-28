<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            // the node the number lives on; the number is unique within the tenant only (plan D6)
            $table->foreignId('pbx_node_id')->constrained()->restrictOnDelete();
            $table->string('extension_number', 10);
            $table->string('display_name');
            $table->string('status')->default('active');
            // optimistic lock: bumped on every change
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['tenant_id', 'extension_number']);
            $table->index('pbx_node_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extensions');
    }
};
