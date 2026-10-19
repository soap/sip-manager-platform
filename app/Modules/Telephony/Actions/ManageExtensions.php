<?php

namespace App\Modules\Telephony\Actions;

use App\Modules\Audit\Audit;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Telephony\Enums\ExtensionStatus;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates, changes and deletes the extensions of the tenant in the context. A number must lie in
 * the tenant's block on its node; the number cannot change later, since phones register with it.
 * Writing to the node is the provisioning step's job, not this one's.
 */
class ManageExtensions
{
    public function __construct(private Audit $audit, private TenantContext $context) {}

    public function create(string $number, string $displayName): Extension
    {
        $assignment = $this->assignment();

        if (! $assignment->isActive()) {
            throw ValidationException::withMessages(['extension_number' => 'This tenant\'s numbers are suspended.']);
        }

        if (! $assignment->contains($number)) {
            throw ValidationException::withMessages(['extension_number' => "Use a number from {$assignment->rangeLabel()}."]);
        }

        if (Extension::query()->where('extension_number', $number)->exists()) {
            throw ValidationException::withMessages(['extension_number' => 'This number is already in use.']);
        }

        try {
            return DB::transaction(function () use ($assignment, $number, $displayName): Extension {
                $extension = Extension::query()->create([
                    'sip_node_id' => $assignment->sip_node_id,
                    'extension_number' => $number,
                    'display_name' => $displayName,
                ]);

                $this->audit->record('extension.created', $extension, ['number' => $number, 'display_name' => $displayName]);

                return $extension;
            });
        } catch (UniqueConstraintViolationException) {
            // a request for the same number won the race
            throw ValidationException::withMessages(['extension_number' => 'This number is already in use.']);
        }
    }

    public function update(Extension $extension, string $displayName, ExtensionStatus $status): void
    {
        $before = ['display_name' => $extension->display_name, 'status' => $extension->status->value];

        $extension->update(['display_name' => $displayName, 'status' => $status]);

        if ($extension->wasChanged()) {
            $this->audit->record('extension.updated', $extension, [
                'number' => $extension->extension_number,
                'from' => $before,
                'to' => ['display_name' => $displayName, 'status' => $status->value],
            ]);
        }
    }

    public function delete(Extension $extension): void
    {
        $extension->delete();

        $this->audit->record('extension.deleted', $extension, ['number' => $extension->extension_number, 'display_name' => $extension->display_name]);
    }

    /**
     * The lowest free number in the tenant's block, to suggest for the next extension.
     */
    public function nextFreeNumber(): ?string
    {
        $assignment = $this->context->tenant()?->pbxAssignment;

        if ($assignment === null) {
            return null;
        }

        $used = Extension::query()->pluck('extension_number')->map(fn (string $number): int => (int) $number)->flip();

        for ($number = $assignment->number_range_start; $number <= $assignment->number_range_end; $number++) {
            if (! $used->has($number)) {
                return (string) $number;
            }
        }

        return null;
    }

    private function assignment(): TenantPbxAssignment
    {
        $assignment = $this->context->tenant()?->pbxAssignment;

        if ($assignment === null) {
            throw ValidationException::withMessages(['extension_number' => 'This tenant has no PBX node yet. A platform admin assigns one.']);
        }

        return $assignment;
    }
}
