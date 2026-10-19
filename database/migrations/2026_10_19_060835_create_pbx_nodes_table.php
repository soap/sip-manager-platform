<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pbx_nodes', function (Blueprint $table) {
            $table->id();
            // the server id in SIP Deployer, which owns the node; everything else here is a copy of its manifest
            $table->unsignedBigInteger('deployer_server_id')->unique();
            $table->unsignedInteger('manifest_version');
            $table->string('name');
            $table->string('hostname')->nullable();
            $table->string('sip_domain')->nullable();
            $table->string('environment');
            $table->string('status');
            $table->string('region')->nullable();
            $table->json('addresses');
            $table->json('services');
            $table->json('endpoints');
            $table->json('dialplan')->nullable();
            $table->string('credentials_version', 64)->nullable();
            $table->json('connection_checks')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pbx_nodes');
    }
};
