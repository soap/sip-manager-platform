<?php

use App\Modules\Telephony\Actions\ManageCallingProfiles;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tenants created before plan D7 have no calling profiles; give them the ones a new tenant gets.
     * Their extensions keep the same permissions, since the default "Internal only" allows nothing
     * outside the tenant, as a missing profile did.
     */
    public function up(): void
    {
        $profiles = app(ManageCallingProfiles::class);

        Tenant::query()
            ->whereNotIn('id', DB::table('calling_profiles')->select('tenant_id'))
            ->each(fn (Tenant $tenant) => $profiles->createDefaults($tenant));
    }
};
