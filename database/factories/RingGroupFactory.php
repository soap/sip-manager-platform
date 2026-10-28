<?php

namespace Database\Factories;

use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Telephony\Enums\FailoverType;
use App\Modules\Telephony\Enums\RingStrategy;
use App\Modules\Telephony\Models\DialNumber;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Telephony\Models\RingGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Use on() to place it on an assignment; withMembers() adds extensions in order. Like
 * ManageRingGroups, it registers the number.
 *
 * @extends Factory<RingGroup>
 */
class RingGroupFactory extends Factory
{
    protected $model = RingGroup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => (string) fake()->unique()->numberBetween(20500, 20999),
            'name' => fake()->randomElement(['Sales', 'Support', 'Front desk']),
            'strategy' => RingStrategy::RingAll,
            'ring_seconds' => 20,
            'failover_type' => FailoverType::Hangup,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (RingGroup $group): void {
            DialNumber::withoutTenantScope()->create([
                'tenant_id' => $group->tenant_id,
                'pbx_node_id' => $group->pbx_node_id,
                'number' => $group->number,
                'owner_type' => RingGroup::MORPH,
                'owner_id' => $group->id,
            ]);
        });
    }

    /**
     * A ring group of the assignment's tenant on its node, numbered 20500–20999.
     */
    public function on(TenantPbxAssignment $assignment): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $assignment->tenant_id,
            'pbx_node_id' => $assignment->pbx_node_id,
            'number' => '20'.fake()->unique()->numberBetween(500, 999),
        ]);
    }

    /**
     * @param  list<Extension>  $extensions  in ringing order
     */
    public function withMembers(array $extensions): static
    {
        return $this->afterCreating(function (RingGroup $group) use ($extensions): void {
            foreach ($extensions as $index => $extension) {
                $group->members()->attach($extension->id, ['position' => $index + 1]);
            }
        });
    }
}
