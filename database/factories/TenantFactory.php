<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantMembership;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        $slug = Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999);

        return [
            'name' => $name,
            'slug' => $slug,
            'code' => (string) fake()->unique()->numberBetween(Tenant::FIRST_CODE, Tenant::LAST_CODE),
            'sip_domain' => "{$slug}.sip.example.com",
            'status' => TenantStatus::Active,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => TenantStatus::Suspended]);
    }

    /**
     * Adds the user as a member with the role.
     */
    public function withMember(User $user, TenantRole $role = TenantRole::Member): static
    {
        return $this->afterCreating(function (Tenant $tenant) use ($user, $role): void {
            TenantMembership::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role]);
        });
    }
}
