<?php

namespace Database\Factories;

use App\Modules\Nodes\Models\TenantNumberRange;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Telephony\Enums\ExtensionStatus;
use App\Modules\Telephony\Models\DialNumber;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Telephony\Models\SipCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Use on() to place it on an assignment: its tenant, node and a number with the tenant's prefix.
 * Like ManageExtensions, it registers the number and gives the extension a SIP credential.
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
            'extension_number' => (string) fake()->unique()->numberBetween(20000, 20999),
            'display_name' => fake()->name(),
            'status' => ExtensionStatus::Active,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Extension $extension): void {
            DialNumber::withoutTenantScope()->create([
                'tenant_id' => $extension->tenant_id,
                'pbx_node_id' => $extension->pbx_node_id,
                'number' => $extension->extension_number,
                'owner_type' => Extension::MORPH,
                'owner_id' => $extension->id,
            ]);

            SipCredential::withoutTenantScope()->create([
                'tenant_id' => $extension->tenant_id,
                'extension_id' => $extension->id,
                'pbx_node_id' => $extension->pbx_node_id,
                'sip_username' => $extension->extension_number,
                'secret' => SipCredential::newPassword(),
            ]);
        });
    }

    /**
     * An extension of the assignment's tenant on its node, numbered with the tenant's prefix.
     */
    public function on(TenantPbxAssignment $assignment): static
    {
        return $this->state(function () use ($assignment): array {
            $prefix = TenantNumberRange::query()->where('tenant_id', $assignment->tenant_id)->value('prefix') ?? '20';

            return [
                'tenant_id' => $assignment->tenant_id,
                'pbx_node_id' => $assignment->pbx_node_id,
                'extension_number' => $prefix.str_pad((string) fake()->unique()->numberBetween(0, 999), 3, '0', STR_PAD_LEFT),
            ];
        });
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['status' => ExtensionStatus::Disabled]);
    }
}
