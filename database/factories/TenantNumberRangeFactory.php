<?php

namespace Database\Factories;

use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Nodes\Models\TenantNumberRange;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A prefix on its own; TenantPbxAssignmentFactory creates one with each assignment.
 *
 * @extends Factory<TenantNumberRange>
 */
class TenantNumberRangeFactory extends Factory
{
    protected $model = TenantNumberRange::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'pbx_node_id' => PbxNode::factory(),
            'prefix' => (string) fake()->numberBetween(TenantNumberRange::FIRST_PREFIX, TenantNumberRange::LAST_PREFIX),
        ];
    }
}
