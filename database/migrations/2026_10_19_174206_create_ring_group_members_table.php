<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ring_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ring_group_id')->constrained()->cascadeOnDelete();
            // deleting the extension takes it out of the group; ManageExtensions then recompiles the group
            $table->foreignId('extension_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['ring_group_id', 'extension_id']);
            $table->index('extension_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ring_group_members');
    }
};
