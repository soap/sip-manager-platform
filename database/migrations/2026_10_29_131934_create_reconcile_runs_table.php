<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // plan v0.2 §6.4: each comparison of a node's rows with the desired state
        Schema::create('reconcile_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pbx_node_id')->constrained()->cascadeOnDelete();
            // running, clean, drift or failed
            $table->string('status')->default('running');
            // schedule, sync (a service changed version) or manual
            $table->string('trigger');
            $table->unsignedInteger('targets_checked')->default(0);
            $table->unsignedInteger('missing_count')->default(0);
            $table->unsignedInteger('changed_count')->default(0);
            $table->unsignedInteger('unexpected_count')->default(0);
            // targets written again to fix missing or changed rows
            $table->unsignedInteger('fixed_count')->default(0);
            // [{kind, target, item}], without secrets
            $table->json('drift')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['pbx_node_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconcile_runs');
    }
};
