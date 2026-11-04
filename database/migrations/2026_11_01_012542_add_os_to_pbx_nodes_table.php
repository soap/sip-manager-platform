<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pbx_nodes', function (Blueprint $table) {
            // the operating system release the deployer read from /etc/os-release, e.g. "ubuntu-24.04"
            $table->string('os')->nullable()->after('region');
        });
    }

    public function down(): void
    {
        Schema::table('pbx_nodes', function (Blueprint $table) {
            $table->dropColumn('os');
        });
    }
};
