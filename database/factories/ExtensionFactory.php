<?php

namespace Database\Factories;

use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Telephony\Enums\ExtensionStatus;
use App\Modules\Telephony\Models\Extension;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Give it a tenant and node with for(), or use on() to take both from an assignment.
 *
 * @extends Factory<Extension>
 */
class ExtensionFactory extends Factory
{
    protected $model = Extension::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'extension_number' => (string) fake()->unique()->numberBetween(1000, 1999),
            'display_name' => fake()->name(),
            'status' => ExtensionStatus::Active,
        ];
    }

    /**
     * An extension of the assignment's tenant on its node.
     */
    public function on(TenantPbxAssignment $assignment): static
    {
        return $this->state(fn (): array => ['tenant_id' => $assignment->tenant_id, 'sip_node_id' => $assignment->sip_node_id]);
    }
}
