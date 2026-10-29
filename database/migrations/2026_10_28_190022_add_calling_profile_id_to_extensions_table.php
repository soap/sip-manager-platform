<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extensions', function (Blueprint $table) {
            // null = the tenant's default profile; a profile in use cannot be deleted
            $table->foreignId('calling_profile_id')->nullable()->after('voicemail_pin')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('extensions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('calling_profile_id');
        });
    }
};
