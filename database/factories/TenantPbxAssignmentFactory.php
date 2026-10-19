<?php

namespace Database\Factories;

use App\Modules\Nodes\Enums\AssignmentStatus;
use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Nodes\Models\TenantNumberRange;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A tenant on a node with the lowest free prefix there (20 on a new node: numbers 20000–20999),
 * or the one given with prefix().
 *
 * @extends Factory<TenantPbxAssignment>
 */
class TenantPbxAssignmentFactory extends Factory
{
    protected $model = TenantPbxAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'pbx_node_id' => PbxNode::factory(),
            'status' => AssignmentStatus::Active,
        ];
    }

    /**
     * Gives the tenant the lowest free prefix on the node.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (TenantPbxAssignment $assignment): void {
            TenantNumberRange::query()->create([
                'tenant_id' => $assignment->tenant_id,
                'pbx_node_id' => $assignment->pbx_node_id,
                'prefix' => TenantNumberRange::nextFreePrefix($assignment->pbx_node_id),
            ]);
        });
    }

    public function prefix(string $prefix): static
    {
        return $this->afterCreating(function (TenantPbxAssignment $assignment) use ($prefix): void {
            TenantNumberRange::query()->where('tenant_id', $assignment->tenant_id)->update(['prefix' => $prefix]);
        });
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => AssignmentStatus::Suspended]);
    }
}
