<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('config_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pbx_node_id')->constrained()->restrictOnDelete();
            // increasing per node
            $table->unsignedBigInteger('revision_number');
            // what the revision brings to the node, e.g. "number:10421001" (tenant code and number)
            // or "domain:1042"; a newer revision of the same target supersedes an older one that
            // has not been applied yet
            $table->string('target');
            // the change that asked for it (e.g. extension 12), kept after the extension is deleted
            $table->string('aggregate_type');
            $table->unsignedBigInteger('aggregate_id');
            $table->string('checksum', 64)->nullable();
            // the rows written, without secrets (ha1 is left out)
            $table->json('compiled')->nullable();
            $table->string('status')->default('draft');
            $table->json('validation_report')->nullable();
            $table->json('apply_result')->nullable();
            $table->timestamps();

            $table->unique(['pbx_node_id', 'revision_number']);
            $table->index(['pbx_node_id', 'target', 'id']);
            $table->index(['aggregate_type', 'aggregate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('config_revisions');
    }
};
