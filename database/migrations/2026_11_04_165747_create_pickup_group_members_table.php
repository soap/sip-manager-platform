<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pickup_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pickup_group_id')->constrained()->cascadeOnDelete();
            // deleting the extension takes it out of the group; ManageExtensions then recompiles the calling dialplan
            $table->foreignId('extension_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // a ringing phone carries one pickup mark, so an extension is in at most one group
            $table->unique('extension_id');
            $table->index('pickup_group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pickup_group_members');
    }
};
