<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // written in the same transaction as the change it announces, then dispatched by the relay,
        // so a committed change is never lost between the database and the queue
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->id();
            $table->string('topic');
            $table->json('payload');
            $table->timestamp('available_at');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();

            $table->index(['dispatched_at', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
