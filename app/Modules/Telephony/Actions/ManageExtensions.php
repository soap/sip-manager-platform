<?php

namespace App\Modules\Telephony\Actions;

use App\Modules\Audit\Audit;
use App\Modules\Nodes\Models\TenantNumberRange;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Telephony\Enums\ExtensionStatus;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates, changes and deletes the extensions of the tenant in the context. A number is the
 * tenant's two-digit prefix followed by three digits (e.g. 20101); it cannot change later, since
 * phones register with it. Writing to the node is the provisioning step's job, not this one's.
 */
class ManageExtensions
{
    public function __construct(private Audit $audit, private TenantContext $context) {}

    public function create(string $number, string $displayName): Extension
    {
        [$assignment, $range] = $this->placement();

        if (! $assignment->isActive()) {
            throw ValidationException::withMessages(['extension_number' => 'This tenant\'s numbers are suspended.']);
        }

        if (! $range->contains($number)) {
            throw ValidationException::withMessages(['extension_number' => "Use a number from {$range->label()}."]);
        }

        if (Extension::query()->where('extension_number', $number)->exists()) {
            throw ValidationException::withMessages(['extension_number' => 'This number is already in use.']);
        }

        try {
            return DB::transaction(function () use ($assignment, $number, $displayName): Extension {
                $extension = Extension::query()->create([
                    'pbx_node_id' => $assignment->pbx_node_id,
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
     * The lowest free number of the tenant from PP001 up (PP000 last), to suggest for the next
     * extension.
     */
    public function nextFreeNumber(): ?string
    {
        $range = $this->context->tenant()?->numberRange;

        if ($range === null) {
            return null;
        }

        $used = Extension::query()->pluck('extension_number')->flip();
        $last = 10 ** TenantNumberRange::EXTENSION_DIGITS - 1;

        foreach ([...range(1, $last), 0] as $extension) {
            $number = $range->prefix.str_pad((string) $extension, TenantNumberRange::EXTENSION_DIGITS, '0', STR_PAD_LEFT);

            if (! $used->has($number)) {
                return $number;
            }
        }

        return null;
    }

    /**
     * @return array{TenantPbxAssignment, TenantNumberRange}
     */
    private function placement(): array
    {
        $tenant = $this->context->tenant();
        $assignment = $tenant?->pbxAssignment;
        $range = $tenant?->numberRange;

        if ($assignment === null || $range === null) {
            throw ValidationException::withMessages(['extension_number' => 'This tenant has no PBX node yet. A platform admin assigns one.']);
        }

        return [$assignment, $range];
    }
}
