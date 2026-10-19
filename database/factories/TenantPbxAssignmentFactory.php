<?php

namespace Database\Factories;

use App\Modules\Nodes\Enums\AssignmentStatus;
use App\Modules\Nodes\Models\SipNode;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A tenant on a node with the numbers 1000–1999.
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
            'sip_node_id' => SipNode::factory(),
            'number_range_start' => 1000,
            'number_range_end' => 1999,
            'status' => AssignmentStatus::Active,
        ];
    }

    public function numbers(int $start, int $end): static
    {
        return $this->state(fn (): array => ['number_range_start' => $start, 'number_range_end' => $end]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => AssignmentStatus::Suspended]);
    }
}
