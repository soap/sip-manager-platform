<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // plan §7: how far the CDR of each node has been copied
        Schema::create('cdr_cursors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pbx_node_id')->unique()->constrained()->cascadeOnDelete();
            // every CDR row that ended before this was copied (the next sync reads a few minutes back)
            $table->timestamp('synced_until')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->unsignedInteger('last_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cdr_cursors');
    }
};
