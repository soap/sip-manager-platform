<?php

namespace App\Modules\Nodes\Actions;

use App\Modules\Audit\Audit;
use App\Modules\Nodes\Enums\AssignmentStatus;
use App\Modules\Nodes\Models\SipNode;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Places tenants on nodes with a block of numbers each. The blocks of one node never overlap, so
 * extension numbers stay unique on the node's shared SIP domain and dialplan. The node row is
 * locked while a block is checked, so two admins cannot hand out the same numbers at once.
 */
class ManageAssignments
{
    public function __construct(private Audit $audit) {}

    public function assign(Tenant $tenant, SipNode $node, int $start, int $end): TenantPbxAssignment
    {
        if ($node->removed_at !== null) {
            throw ValidationException::withMessages(['sip_node_id' => 'This node has been removed from SIP Deployer.']);
        }

        return DB::transaction(function () use ($tenant, $node, $start, $end): TenantPbxAssignment {
            SipNode::query()->whereKey($node->id)->lockForUpdate()->first();

            if (TenantPbxAssignment::query()->where('tenant_id', $tenant->id)->exists()) {
                throw ValidationException::withMessages(['tenant_id' => 'This tenant already has a node.']);
            }

            $this->ensureFree($node->id, $start, $end);

            $assignment = TenantPbxAssignment::query()->create([
                'tenant_id' => $tenant->id,
                'sip_node_id' => $node->id,
                'number_range_start' => $start,
                'number_range_end' => $end,
            ]);

            $this->audit->record('pbx.assigned', $assignment, ['node' => $node->name, 'numbers' => $assignment->rangeLabel()], $tenant);

            return $assignment;
        });
    }

    /**
     * Changes the block or the status. A block may grow into free numbers or shrink, but never
     * leave out a number the tenant already uses.
     */
    public function update(TenantPbxAssignment $assignment, int $start, int $end, AssignmentStatus $status): void
    {
        DB::transaction(function () use ($assignment, $start, $end, $status): void {
            SipNode::query()->whereKey($assignment->sip_node_id)->lockForUpdate()->first();

            $this->ensureFree($assignment->sip_node_id, $start, $end, $assignment);

            $outside = Extension::withoutTenantScope()
                ->where('tenant_id', $assignment->tenant_id)
                ->get(['extension_number'])
                ->reject(fn (Extension $extension): bool => (int) $extension->extension_number >= $start && (int) $extension->extension_number <= $end);

            if ($outside->isNotEmpty()) {
                throw ValidationException::withMessages(['number_range_start' => 'The block must keep the numbers in use: '.$outside->pluck('extension_number')->sort()->implode(', ').'.']);
            }

            $before = ['numbers' => $assignment->rangeLabel(), 'status' => $assignment->status->value];
            $assignment->update(['number_range_start' => $start, 'number_range_end' => $end, 'status' => $status]);

            $this->audit->record('pbx.assignment_updated', $assignment, [
                'from' => $before,
                'to' => ['numbers' => $assignment->rangeLabel(), 'status' => $status->value],
            ], $assignment->tenant);
        });
    }

    /**
     * Takes the tenant off its node. Only once it has no extensions there, so no phone loses its
     * number by accident.
     */
    public function unassign(TenantPbxAssignment $assignment): void
    {
        if (Extension::withoutTenantScope()->where('tenant_id', $assignment->tenant_id)->exists()) {
            throw ValidationException::withMessages(['assignment' => 'Delete the tenant\'s extensions before taking it off the node.']);
        }

        $assignment->delete();

        $this->audit->record('pbx.unassigned', $assignment, ['node' => $assignment->node->name, 'numbers' => $assignment->rangeLabel()], $assignment->tenant);
    }

    private function ensureFree(int $nodeId, int $start, int $end, ?TenantPbxAssignment $except = null): void
    {
        $taken = TenantPbxAssignment::query()
            ->overlapping($nodeId, $start, $end)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except?->id))
            ->with('tenant:id,name')
            ->first();

        if ($taken !== null) {
            throw ValidationException::withMessages(['number_range_start' => "These numbers overlap {$taken->tenant->name} ({$taken->rangeLabel()}) on this node."]);
        }
    }
}
