<?php

namespace App\Modules\Nodes\Actions;

use App\Modules\Audit\Audit;
use App\Modules\Nodes\Enums\AssignmentStatus;
use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Nodes\Models\TenantNumberRange;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Provisioning\Actions\RequestProvisioning;
use App\Modules\Provisioning\Enums\OperationType;
use App\Modules\Provisioning\Models\ProvisioningOperation;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Places tenants on nodes, each with a two-digit prefix that is unique on its node, so extension
 * numbers stay unique on the node's shared SIP domain and dialplan (plan v0.2, D1). The prefix is
 * fixed once the tenant has extensions, since phones register with the full number.
 */
class ManageAssignments
{
    public function __construct(private Audit $audit, private RequestProvisioning $provisioning) {}

    public function assign(Tenant $tenant, PbxNode $node, string $prefix): TenantPbxAssignment
    {
        if ($node->removed_at !== null) {
            throw ValidationException::withMessages(['pbx_node_id' => 'This node has been removed from SIP Deployer.']);
        }

        $this->ensureValidPrefix($prefix);

        return $this->guardPrefix(fn (): TenantPbxAssignment => DB::transaction(function () use ($tenant, $node, $prefix): TenantPbxAssignment {
            if (TenantPbxAssignment::query()->where('tenant_id', $tenant->id)->exists()) {
                throw ValidationException::withMessages(['tenant_id' => 'This tenant already has a node.']);
            }

            $this->ensureFree($node->id, $prefix);

            $assignment = TenantPbxAssignment::query()->create(['tenant_id' => $tenant->id, 'pbx_node_id' => $node->id]);
            $range = TenantNumberRange::query()->create(['tenant_id' => $tenant->id, 'pbx_node_id' => $node->id, 'prefix' => $prefix]);

            $this->audit->record('pbx.assigned', $assignment, ['node' => $node->name, 'prefix' => $prefix, 'numbers' => $range->label()], $tenant);

            return $assignment;
        }));
    }

    public function update(TenantPbxAssignment $assignment, string $prefix, AssignmentStatus $status): void
    {
        $this->ensureValidPrefix($prefix);

        $range = $assignment->numberRange;
        $before = ['prefix' => $range?->prefix, 'status' => $assignment->status->value];

        if ($range !== null && $range->prefix !== $prefix && $assignment->extensions()->exists()) {
            throw ValidationException::withMessages(['prefix' => 'The prefix cannot change while the tenant has extensions.']);
        }

        $this->guardPrefix(fn () => DB::transaction(function () use ($assignment, $range, $prefix, $status): void {
            if ($range?->prefix !== $prefix) {
                $this->ensureFree($assignment->pbx_node_id, $prefix, $range);
            }

            TenantNumberRange::query()->updateOrCreate(
                ['tenant_id' => $assignment->tenant_id],
                ['pbx_node_id' => $assignment->pbx_node_id, 'prefix' => $prefix],
            );
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
            }
        }));

        $this->audit->record('pbx.assignment_updated', $assignment, [
            'from' => $before,
            'to' => ['prefix' => $prefix, 'status' => $status->value],
        ], $assignment->tenant);
    }

    /**
     * Takes the tenant off its node. Only once it has no extensions there, so no phone loses its
     * number by accident.
     */
    public function unassign(TenantPbxAssignment $assignment): void
    {
        if ($assignment->extensions()->exists()) {
            throw ValidationException::withMessages(['assignment' => 'Delete the tenant\'s extensions before taking it off the node.']);
        }

        $prefix = $assignment->numberRange?->prefix;

        DB::transaction(function () use ($assignment): void {
            TenantNumberRange::query()->where('tenant_id', $assignment->tenant_id)->delete();
            $assignment->delete();
        });

        $this->audit->record('pbx.unassigned', $assignment, ['node' => $assignment->node->name, 'prefix' => $prefix], $assignment->tenant);
    }

    private function ensureValidPrefix(string $prefix): void
    {
        if (! TenantNumberRange::isValidPrefix($prefix)) {
            throw ValidationException::withMessages(['prefix' => 'Use a prefix from '.TenantNumberRange::FIRST_PREFIX.' to '.TenantNumberRange::LAST_PREFIX.'.']);
        }
    }

    private function ensureFree(int $nodeId, string $prefix, ?TenantNumberRange $except = null): void
    {
        $taken = TenantNumberRange::query()
            ->where('pbx_node_id', $nodeId)
            ->where('prefix', $prefix)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except?->id))
            ->with('tenant:id,name')
            ->first();

        if ($taken !== null) {
            throw ValidationException::withMessages(['prefix' => "Prefix {$prefix} belongs to {$taken->tenant->name} on this node."]);
        }
    }

    /**
     * Turns a lost race for the same prefix (the unique index on node and prefix) into the same
     * validation error as the check before it.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function guardPrefix(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['prefix' => 'This prefix was just taken on this node. Pick another.']);
        }
    }
}
