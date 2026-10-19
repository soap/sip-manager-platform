<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_number_ranges', function (Blueprint $table) {
            $table->id();
            // one range per tenant in the MVP
            $table->foreignUlid('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('pbx_node_id')->constrained()->restrictOnDelete();
            // two digits, 20–89: the tenant's numbers are PPEEE, e.g. 20000–20999
            $table->char('prefix', 2);
            $table->timestamps();

            $table->unique(['pbx_node_id', 'prefix']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_number_ranges');
    }
};
