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
            // the node the number lives on; every tenant on a node shares its SIP domain and dialplan
            $table->foreignId('sip_node_id')->constrained()->restrictOnDelete();
            $table->string('extension_number', 10);
            $table->string('display_name');
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'extension_number']);
            $table->unique(['sip_node_id', 'extension_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extensions');
    }
};
