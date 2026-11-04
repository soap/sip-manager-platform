<?php

namespace Database\Factories;

use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Telephony\Models\PickupGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Use on() for the tenant of an assignment; withMembers() adds extensions.
 *
 * @extends Factory<PickupGroup>
 */
class PickupGroupFactory extends Factory
{
    protected $model = PickupGroup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Sales', 'Support', 'Front desk', 'Accounts', 'Warehouse']),
        ];
    }

    public function on(TenantPbxAssignment $assignment): static
    {
        return $this->state(fn (): array => ['tenant_id' => $assignment->tenant_id]);
    }

    /**
     * @param  list<Extension>  $extensions
     */
    public function withMembers(array $extensions): static
    {
        return $this->afterCreating(function (PickupGroup $group) use ($extensions): void {
            $group->members()->attach(array_map(fn (Extension $extension): int => $extension->id, $extensions));
        });
    }
}
