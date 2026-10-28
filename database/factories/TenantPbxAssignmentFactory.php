<?php

namespace Database\Factories;

use App\Modules\Nodes\Enums\AssignmentStatus;
use App\Modules\Nodes\Models\PbxNode;
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
     * A new tenant with the code, e.g. 1020: its numbers are 1020<number> in the dialplan.
     */
    public function code(string $code): static
    {
        return $this->state(fn (): array => ['tenant_id' => Tenant::factory()->state(['code' => $code])]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => AssignmentStatus::Suspended]);
    }
}
