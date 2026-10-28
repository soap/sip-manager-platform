<?php

namespace App\Modules\Tenancy\Actions;

use App\Modules\Audit\Audit;
use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantMembership;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateTenant
{
    public function __construct(private InviteUser $invite, private Audit $audit) {}

    public function handle(string $name, string $slug, string $ownerName, string $ownerEmail): Tenant
    {
        return DB::transaction(function () use ($name, $slug, $ownerName, $ownerEmail): Tenant {
            $tenant = $this->createWithCode($name, $slug);
            $owner = $this->invite->handle($ownerName, $ownerEmail);

            TenantMembership::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $owner->id,
                'role' => TenantRole::Owner,
            ]);

            $this->audit->record('tenant.created', $tenant, ['name' => $name, 'slug' => $slug, 'code' => $tenant->code, 'sip_domain' => $tenant->sip_domain, 'owner' => $owner->email], $tenant);

            return $tenant;
        });
    }

    /**
     * Gives the tenant the next code and its SIP domain (plan D6).
     *
     * @throws ValidationException when no code is left or the domain is taken
     */
    private function createWithCode(string $name, string $slug): Tenant
    {
        $code = Tenant::nextCode() ?? throw ValidationException::withMessages(['slug' => 'Every tenant code is taken.']);
        $domain = Tenant::sipDomainFor($slug);

        if (Tenant::query()->where('sip_domain', $domain)->exists()) {
            throw ValidationException::withMessages(['slug' => "The SIP domain {$domain} belongs to another tenant."]);
        }

        try {
            return Tenant::query()->create(['name' => $name, 'slug' => $slug, 'code' => $code, 'sip_domain' => $domain]);
        } catch (UniqueConstraintViolationException) {
            // another tenant took the code at the same moment
            throw ValidationException::withMessages(['slug' => 'Another tenant was just created. Try again.']);
        }
    }
}
