<?php

namespace Database\Factories;

use App\Modules\Telephony\Enums\CallCategory;
use App\Modules\Telephony\Enums\CallPermission;
use App\Modules\Telephony\Enums\CrossTenantScope;
use App\Modules\Telephony\Models\CallingProfile;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A profile that may only call within its tenant until permissions() opens categories.
 *
 * @extends Factory<CallingProfile>
 */
class CallingProfileFactory extends Factory
{
    protected $model = CallingProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->words(2, true),
            'categories' => [],
            'cross_tenant_scope' => CrossTenantScope::All,
            'is_default' => false,
        ];
    }

    /**
     * @param  array<string, CallPermission>  $permissions  category value => permission
     */
    public function permissions(array $permissions): static
    {
        return $this->state(fn (): array => ['categories' => array_map(fn (CallPermission $permission): string => $permission->value, $permissions)]);
    }

    public function crossTenant(CallPermission $permission = CallPermission::Allow): static
    {
        return $this->state(fn (array $attributes): array => ['categories' => [...$attributes['categories'], CallCategory::CrossTenant->value => $permission->value]]);
    }

    public function default(): static
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }
}
