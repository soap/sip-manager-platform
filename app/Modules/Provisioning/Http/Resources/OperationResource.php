<?php

namespace App\Modules\Provisioning\Http\Resources;

use App\Modules\Provisioning\Models\ProvisioningOperation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProvisioningOperation
 */
class OperationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'attempts' => $this->attempts,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'next_attempt_at' => $this->next_attempt_at?->toIso8601String(),
            'target' => ['type' => $this->aggregate_type, 'id' => $this->aggregate_id],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
