<?php

namespace App\Modules\Tenancy\Actions;

use App\Modules\Audit\Audit;
use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantMembership;
use Illuminate\Support\Facades\DB;

class CreateTenant
{
    public function __construct(private InviteUser $invite, private Audit $audit) {}

    public function handle(string $name, string $slug, string $ownerName, string $ownerEmail): Tenant
    {
        return DB::transaction(function () use ($name, $slug, $ownerName, $ownerEmail): Tenant {
            $tenant = Tenant::query()->create(['name' => $name, 'slug' => $slug]);
            $owner = $this->invite->handle($ownerName, $ownerEmail);

            TenantMembership::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $owner->id,
                'role' => TenantRole::Owner,
            ]);

            $this->audit->record('tenant.created', $tenant, ['name' => $name, 'slug' => $slug, 'owner' => $owner->email], $tenant);

            return $tenant;
        });
    }
}
