<?php

namespace App\Modules\Telephony\Actions;

use App\Modules\Audit\Audit;
use App\Modules\Provisioning\Actions\RequestProvisioning;
use App\Modules\Provisioning\Enums\OperationType;
use App\Modules\Provisioning\Models\ProvisioningOperation;
use App\Modules\Telephony\Enums\ExtensionStatus;
use App\Modules\Telephony\Exceptions\StaleVersion;
use App\Modules\Telephony\Models\CallingProfile;
use App\Modules\Telephony\Models\DialNumber;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Telephony\Models\SipCredential;
use App\Modules\Telephony\NumberAllocator;
use App\Modules\Telephony\RingGroupDependents;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates, changes and deletes the extensions of the tenant in the context. A number is the
 * tenant's own (e.g. 201, see NumberAllocator); it cannot change later, since phones register with
 * it. Every change that reaches the node requests provisioning in the same transaction, so the node
 * follows the desired state.
 */
class ManageExtensions
{
    public function __construct(
        private Audit $audit,
        private RequestProvisioning $provisioning,
        private NumberAllocator $numbers,
        private RingGroupDependents $ringGroups,
    ) {}

    /**
     * @return array{extension: Extension, password: string, operation: ProvisioningOperation}
     */
    public function create(string $number, string $displayName): array
    {
        $assignment = $this->numbers->ensureAvailable($number, 'extension_number');

        try {
            return DB::transaction(function () use ($assignment, $number, $displayName): array {
                $extension = Extension::query()->create([
                    'pbx_node_id' => $assignment->pbx_node_id,
                    'extension_number' => $number,
                    'display_name' => $displayName,
                ]);

                DialNumber::query()->create([
                    'pbx_node_id' => $assignment->pbx_node_id,
                    'number' => $number,
                    'owner_type' => Extension::MORPH,
                    'owner_id' => $extension->id,
                ]);

                $password = SipCredential::newPassword();
                SipCredential::query()->create([
                    'extension_id' => $extension->id,
                    'pbx_node_id' => $assignment->pbx_node_id,
                    'sip_username' => $number,
                    'secret' => $password,
                ]);

                $operation = $this->provision($extension, OperationType::ExtensionCreated);

                $this->audit->record('extension.created', $extension, ['number' => $number, 'display_name' => $displayName]);

                return ['extension' => $extension, 'password' => $password, 'operation' => $operation];
            });
        } catch (UniqueConstraintViolationException) {
            // a request for the same number won the race
            throw ValidationException::withMessages(['extension_number' => 'This number is already in use.']);
        }
    }

    /**
     * Renames or enables/disables. With a version, refuses a change made against an older copy of
     * the extension (optimistic lock).
     *
     * @return ProvisioningOperation|null the operation when the node has to change (a new status)
     *
     * @throws StaleVersion
     */
    public function update(Extension $extension, string $displayName, ExtensionStatus $status, ?int $expectedVersion = null): ?ProvisioningOperation
    {
        $before = ['display_name' => $extension->display_name, 'status' => $extension->status->value];

        if ($before === ['display_name' => $displayName, 'status' => $status->value]) {
            return null;
        }

        return DB::transaction(function () use ($extension, $displayName, $status, $expectedVersion, $before): ?ProvisioningOperation {
            $operation = null;

            $updated = Extension::query()
                ->whereKey($extension->id)
                ->when($expectedVersion !== null, fn ($query) => $query->where('version', $expectedVersion))
                ->update(['display_name' => $displayName, 'status' => $status, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);

            if ($updated === 0) {
                throw StaleVersion::of('extension', 'extension');
            }

            $extension->refresh();

            if ($before['status'] !== $status->value) {
                $operation = $this->provision($extension, OperationType::ExtensionUpdated);
                // ring groups skip disabled members
                $this->ringGroups->extensionChanged($extension, deleted: false);
            }

            $this->audit->record('extension.updated', $extension, [
                'number' => $extension->extension_number,
                'from' => $before,
                'to' => ['display_name' => $displayName, 'status' => $status->value],
            ]);

            return $operation;
        });
    }

    public function delete(Extension $extension): ProvisioningOperation
    {
        return DB::transaction(function () use ($extension): ProvisioningOperation {
            // before the delete, while its memberships still point at the groups
            $this->ringGroups->extensionChanged($extension, deleted: true);

            DialNumber::query()->where('owner_type', Extension::MORPH)->where('owner_id', $extension->id)->delete();
            $extension->delete();

            // the number is free now, so the node loses its subscriber and dialplan
            $operation = $this->provision($extension, OperationType::ExtensionDeleted);

            $this->audit->record('extension.deleted', $extension, ['number' => $extension->extension_number, 'display_name' => $extension->display_name]);

            return $operation;
        });
    }

    /**
     * A new SIP password, shown once; the old one stops working once the node has the new digest.
     *
     * @return array{password: string, operation: ProvisioningOperation}
     */
    public function rotateCredential(Extension $extension): array
    {
        return DB::transaction(function () use ($extension): array {
            $password = SipCredential::newPassword();

            SipCredential::query()->updateOrCreate(
                ['extension_id' => $extension->id],
                ['pbx_node_id' => $extension->pbx_node_id, 'sip_username' => $extension->extension_number, 'secret' => $password, 'rotated_at' => now()],
            );

            $operation = $this->provision($extension, OperationType::CredentialRotated);

            $this->audit->record('extension.credential_rotated', $extension, ['number' => $extension->extension_number]);

            return ['password' => $password, 'operation' => $operation];
        });
    }

    /**
     * Turns voicemail on (with a new PIN, shown once) or off. The PIN set here wins: a PIN changed
     * from the phone's voicemail menu is put back on the next write of the extension.
     *
     * @return array{pin: string|null, operation: ProvisioningOperation}
     */
    public function setVoicemail(Extension $extension, bool $enabled): array
    {
        if ($enabled && ! $extension->node->hasVoicemail()) {
            throw ValidationException::withMessages(['voicemail' => 'The PBX node of this tenant has no voicemail.']);
        }

        return DB::transaction(function () use ($extension, $enabled): array {
            $pin = $enabled ? Extension::newVoicemailPin() : null;

            $extension->update(['voicemail_enabled' => $enabled, 'voicemail_pin' => $pin, 'version' => $extension->version + 1]);

            $operation = $this->provision($extension, OperationType::ExtensionUpdated);
            // ring groups may leave messages in this box
            $this->ringGroups->extensionChanged($extension, deleted: false);

            $this->audit->record($enabled ? 'extension.voicemail_enabled' : 'extension.voicemail_disabled', $extension, ['number' => $extension->extension_number]);

            return ['pin' => $pin, 'operation' => $operation];
        });
    }

    /**
     * A new voicemail PIN, shown once.
     *
     * @return array{pin: string, operation: ProvisioningOperation}
     */
    public function resetVoicemailPin(Extension $extension): array
    {
        if (! $extension->voicemail_enabled) {
            throw ValidationException::withMessages(['voicemail' => 'Turn voicemail on first.']);
        }

        return DB::transaction(function () use ($extension): array {
            $pin = Extension::newVoicemailPin();

            $extension->update(['voicemail_pin' => $pin, 'version' => $extension->version + 1]);
            $operation = $this->provision($extension, OperationType::CredentialRotated);

            $this->audit->record('extension.voicemail_pin_reset', $extension, ['number' => $extension->extension_number]);

            return ['pin' => $pin, 'operation' => $operation];
        });
    }

    /**
     * Gives the extension a calling profile of the tenant, or the default with null (plan D7).
     *
     * @return ProvisioningOperation|null the operation when the profile changed
     *
     * @throws StaleVersion
     */
    public function setCallingProfile(Extension $extension, ?int $profileId, ?int $expectedVersion = null): ?ProvisioningOperation
    {
        $profile = $profileId === null ? null : CallingProfile::query()->find($profileId)
            ?? throw ValidationException::withMessages(['calling_profile_id' => 'Choose one of the tenant\'s calling profiles.']);

        if ($extension->calling_profile_id === $profile?->id) {
            return null;
        }

        return DB::transaction(function () use ($extension, $profile, $expectedVersion): ProvisioningOperation {
            $before = $extension->callingProfile?->name;

            $updated = Extension::query()
                ->whereKey($extension->id)
                ->when($expectedVersion !== null, fn ($query) => $query->where('version', $expectedVersion))
                ->update(['calling_profile_id' => $profile?->id, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);

            if ($updated === 0) {
                throw StaleVersion::of('extension', 'extension');
            }

            $extension->refresh();
            $operation = $this->provision($extension, OperationType::CallingProfileChanged);

            $this->audit->record('extension.calling_profile_changed', $extension, [
                'number' => $extension->extension_number,
                'from' => $before,
                'to' => $profile?->name,
            ]);

            return $operation;
        });
    }

    /**
     * Writes the extension to its node again, e.g. after a failed operation.
     */
    public function reapply(Extension $extension): ProvisioningOperation
    {
        return DB::transaction(fn (): ProvisioningOperation => $this->provision($extension, OperationType::Reapplied));
    }

    /**
     * The lowest free number of the tenant from 201 up, to suggest for the next extension.
     */
    public function nextFreeNumber(): ?string
    {
        return $this->numbers->nextFree(201);
    }

    private function provision(Extension $extension, OperationType $type): ProvisioningOperation
    {
        return $this->provisioning->forNumber(
            $extension->pbx_node_id,
            $extension->tenant_id,
            $extension->extension_number,
            $type,
            Extension::MORPH,
            $extension->id,
        );
    }
}
