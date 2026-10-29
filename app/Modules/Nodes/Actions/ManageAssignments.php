<?php

namespace App\Modules\Nodes\Actions;

use App\Modules\Audit\Audit;
use App\Modules\Nodes\Enums\AssignmentStatus;
use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Provisioning\Actions\RequestProvisioning;
use App\Modules\Provisioning\Enums\OperationType;
use App\Modules\Provisioning\Models\ProvisioningOperation;
use App\Modules\Telephony\Models\DialNumber;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Telephony\Models\RingGroup;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Places tenants on nodes. The node then serves the tenant's SIP domain: a row of its OpenSIPS
 * domain table whose attrs is the tenant code (plan D6), written through the provisioning pipeline
 * like every other change to a node.
 */
class ManageAssignments
{
    public function __construct(private Audit $audit, private RequestProvisioning $provisioning) {}

    public function assign(Tenant $tenant, PbxNode $node): TenantPbxAssignment
    {
        if ($node->removed_at !== null) {
            throw ValidationException::withMessages(['pbx_node_id' => 'This node has been removed from SIP Deployer.']);
        }

        if (! $node->hasTenantDomains()) {
            throw ValidationException::withMessages(['pbx_node_id' => 'This node serves one shared SIP domain. Reinstall OpenSIPS and Asterisk from SIP Deployer, then sync.']);
        }

        return DB::transaction(function () use ($tenant, $node): TenantPbxAssignment {
            if (TenantPbxAssignment::query()->where('tenant_id', $tenant->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['tenant_id' => 'This tenant already has a node.']);
            }

            $assignment = TenantPbxAssignment::query()->create(['tenant_id' => $tenant->id, 'pbx_node_id' => $node->id]);
            $this->provisionDomain($assignment, OperationType::TenantAssigned);
            $this->provisionCalling($assignment, OperationType::TenantAssigned);

            $this->audit->record('pbx.assigned', $assignment, ['node' => $node->name, 'code' => $tenant->code, 'sip_domain' => $tenant->sip_domain], $tenant);

            return $assignment;
        });
    }

    public function update(TenantPbxAssignment $assignment, AssignmentStatus $status): void
    {
        $before = $assignment->status->value;

        DB::transaction(function () use ($assignment, $status): void {
            $assignment->update(['status' => $status]);

            // suspending or resuming takes every number of the tenant out of or back into service
            if ($assignment->wasChanged('status')) {
                $assignment->extensions()->get()->each(fn (Extension $extension): ProvisioningOperation => $this->provisioning->forNumber(
                    $assignment->pbx_node_id,
                    $assignment->tenant_id,
                    $extension->extension_number,
                    OperationType::AssignmentChanged,
                    Extension::MORPH,
                    $extension->id,
                ));

                RingGroup::withoutTenantScope()->where('tenant_id', $assignment->tenant_id)->get()->each(fn (RingGroup $group): ProvisioningOperation => $this->provisioning->forNumber(
                    $assignment->pbx_node_id,
                    $assignment->tenant_id,
                    $group->number,
                    OperationType::AssignmentChanged,
                    RingGroup::MORPH,
                    $group->id,
                ));

                $this->provisionCalling($assignment, OperationType::AssignmentChanged);
            }
        });

        $this->audit->record('pbx.assignment_updated', $assignment, ['from' => ['status' => $before], 'to' => ['status' => $status->value]], $assignment->tenant);
    }

    /**
     * Takes the tenant off its node. Only once it has no numbers there, so no phone loses its
     * number by accident; the node then stops serving the tenant's domain.
     */
    public function unassign(TenantPbxAssignment $assignment): void
    {
        if ($this->usesNumbers($assignment)) {
            throw ValidationException::withMessages(['assignment' => 'Delete the tenant\'s extensions and ring groups before taking it off the node.']);
        }

        DB::transaction(function () use ($assignment): void {
            $assignment->delete();
            // compiled after the delete, so the domain row and the calling dialplan go away
            $this->provisionDomain($assignment, OperationType::TenantUnassigned);
            $this->provisionCalling($assignment, OperationType::TenantUnassigned);
        });

        $this->audit->record('pbx.unassigned', $assignment, ['node' => $assignment->node->name, 'sip_domain' => $assignment->tenant->sip_domain], $assignment->tenant);
    }

    /**
     * Writes the domain and the calling dialplan again, e.g. after a failed operation or a
     * reinstall of the node.
     *
     * @return ProvisioningOperation the domain's
     */
    public function reapplyDomain(TenantPbxAssignment $assignment): ProvisioningOperation
    {
        return DB::transaction(function () use ($assignment): ProvisioningOperation {
            $this->provisionCalling($assignment, OperationType::Reapplied);

            return $this->provisionDomain($assignment, OperationType::Reapplied);
        });
    }

    /**
     * Whether the tenant has any number on the node (extension, ring group, …).
     */
    private function usesNumbers(TenantPbxAssignment $assignment): bool
    {
        return DialNumber::withoutTenantScope()->where('tenant_id', $assignment->tenant_id)->exists();
    }

    private function provisionDomain(TenantPbxAssignment $assignment, OperationType $type): ProvisioningOperation
    {
        return $this->provisioning->forDomain(
            $assignment->pbx_node_id,
            $assignment->tenant_id,
            $type,
            TenantPbxAssignment::MORPH,
            $assignment->id,
        );
    }

    /**
     * The tenant's calling dialplan (plan D7): calls to other tenants, codes, outbound categories.
     */
    private function provisionCalling(TenantPbxAssignment $assignment, OperationType $type): ProvisioningOperation
    {
        return $this->provisioning->forCalling(
            $assignment->pbx_node_id,
            $assignment->tenant_id,
            $type,
            TenantPbxAssignment::MORPH,
            $assignment->id,
        );
    }
}
