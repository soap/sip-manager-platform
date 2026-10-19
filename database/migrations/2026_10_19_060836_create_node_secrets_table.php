<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_secrets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pbx_node_id')->constrained()->cascadeOnDelete();
            // opensips_db, asterisk_db, ami or ari
            $table->string('kind');
            $table->string('username');
            // encrypted with the app key; never logged
            $table->text('secret');
            $table->timestamp('fetched_at');
            $table->timestamp('rotated_at')->nullable();
            $table->timestamps();

            $table->unique(['pbx_node_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_secrets');
    }
};
