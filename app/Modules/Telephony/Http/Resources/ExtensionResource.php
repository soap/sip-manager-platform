<?php

namespace App\Modules\Telephony\Http\Resources;

use App\Modules\Provisioning\Models\ProvisioningOperation;
use App\Modules\Telephony\Models\Extension;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An extension without its secrets; "provisioning" tells whether the PBX has its latest state and
 * "registration" (GET only) whether its phone is registered.
 *
 * @mixin Extension
 */
class ExtensionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'extension_number' => $this->extension_number,
            'display_name' => $this->display_name,
            'status' => $this->status->value,
            'voicemail_enabled' => $this->voicemail_enabled,
            'version' => $this->version,
            'provisioning' => $this->whenLoaded('latestOperation', fn (): ?array => $this->latestOperation === null ? null : $this->provisioning($this->latestOperation)),
            // read live from the node on GET (ExtensionRegistrations): registered, not_registered or unknown
            'registration' => $this->whenLoaded('registration'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{operation_id: string, status: string, error_code: string|null, error_message: string|null, next_attempt_at: string|null}
     */
    private function provisioning(ProvisioningOperation $operation): array
    {
        return [
            'operation_id' => $operation->id,
            'status' => $operation->status->value,
            'error_code' => $operation->error_code,
            'error_message' => $operation->error_message,
            'next_attempt_at' => $operation->next_attempt_at?->toIso8601String(),
        ];
    }
}
