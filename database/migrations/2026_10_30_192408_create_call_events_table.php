<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // plan §7: the AMI events of the nodes that tell how calls go, kept for 30 days
        Schema::create('call_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pbx_node_id')->constrained()->cascadeOnDelete();
            // the AMI connection the event came over and its place in it: a stored batch read again is ignored
            $table->ulid('session');
            $table->unsignedInteger('sequence');
            // from the tenant code in the dialed number, the UserEvent or an earlier event of the call
            $table->foreignUlid('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 64);
            $table->string('uniqueid', 150)->nullable();
            $table->string('linkedid', 150)->nullable();
            $table->string('channel', 150)->nullable();
            // every field of the event but Privilege, keyed by lower-case name
            $table->json('fields');
            // the node's Timestamp when it sends one, otherwise when the listener read the event
            $table->timestamp('occurred_at', 6);

            $table->unique(['pbx_node_id', 'session', 'sequence']);
            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['pbx_node_id', 'linkedid']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_events');
    }
};
