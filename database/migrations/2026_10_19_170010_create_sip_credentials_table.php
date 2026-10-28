<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sip_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            // one SIP account per extension in the MVP
            $table->foreignId('extension_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('pbx_node_id')->constrained()->restrictOnDelete();
            $table->string('sip_username', 64);
            // encrypted with the app key (plan D4): ha1 is derived from it for the tenant's realm at
            // apply time, so a new SIP domain only needs a re-apply, not new passwords
            $table->text('secret');
            $table->timestamp('rotated_at')->nullable();
            $table->timestamps();

            // the username is unique within the tenant's SIP domain
            $table->unique(['tenant_id', 'sip_username']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sip_credentials');
    }
};
