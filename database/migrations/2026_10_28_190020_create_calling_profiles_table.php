<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calling_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            // category => allow / code / deny (plan D7); a category not listed is deny
            $table->json('categories');
            $table->string('cross_tenant_scope')->default('all');
            // extensions without a profile of their own use the tenant's default
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        // the tenants a profile with cross_tenant_scope "selected" may call
        Schema::create('calling_profile_destinations', function (Blueprint $table) {
            $table->foreignId('calling_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('destination_tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['calling_profile_id', 'destination_tenant_id']);
            $table->index('destination_tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calling_profile_destinations');
        Schema::dropIfExists('calling_profiles');
    }
};
