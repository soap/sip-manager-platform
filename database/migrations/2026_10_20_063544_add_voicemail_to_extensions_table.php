<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extensions', function (Blueprint $table) {
            $table->boolean('voicemail_enabled')->default(false)->after('status');
            // encrypted with the app key; Asterisk needs the PIN itself in its voicemail table
            $table->text('voicemail_pin')->nullable()->after('voicemail_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('extensions', function (Blueprint $table) {
            $table->dropColumn(['voicemail_enabled', 'voicemail_pin']);
        });
    }
};
