<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // plan §7: the AMI event listener's connection to each node
        Schema::create('ami_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pbx_node_id')->unique()->constrained()->cascadeOnDelete();
            // the listener process that holds the node; another one takes over once heartbeat_at is stale
            $table->ulid('listener')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            // connecting, connected, disconnected or stopped
            $table->string('status', 20)->default('disconnected');
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            // events stored since connected_at
            $table->unsignedInteger('events_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ami_connections');
    }
};
