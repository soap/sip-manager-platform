<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            // plan D6: never changes or comes back; the attrs of the tenant's row in the OpenSIPS
            // domain table and the prefix of its numbers in the Asterisk dialplan (<code><number>)
            $table->char('code', 4)->unique();
            // the tenant's SIP domain and digest realm, <slug>.<suffix>; it does not follow the slug
            $table->string('sip_domain')->unique();
            $table->string('status')->default('active');
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
