<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // plan D6: which other tenants may call this one (none, all, selected)
            $table->string('cross_tenant_inbound')->default('none')->after('status');
            // plan D7: salts the md5 of authorization codes in the tenant's dialplan; never changes,
            // since every code's hash depends on it
            $table->string('auth_salt', 32)->nullable()->after('cross_tenant_inbound');
        });

        DB::table('tenants')->whereNull('auth_salt')->pluck('id')->each(
            fn (string $id) => DB::table('tenants')->where('id', $id)->update(['auth_salt' => Str::random(16)]),
        );

        // the tenants allowed to call a tenant whose cross_tenant_inbound is "selected"
        Schema::create('tenant_inbound_allowlist', function (Blueprint $table) {
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('source_tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['tenant_id', 'source_tenant_id']);
            $table->index('source_tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_inbound_allowlist');

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['cross_tenant_inbound', 'auth_salt']);
        });
    }
};
