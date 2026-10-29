<?php

namespace App\Modules\Telephony\Http\Resources;

use App\Modules\Telephony\Models\AuthorizationCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An authorization code without its digits, which are only in the answer that sets them; the id
 * is what CDRs name as "authcode:<id>".
 *
 * @mixin AuthorizationCode
 */
class AuthorizationCodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'user_id' => $this->user_id,
            'categories' => $this->categories,
            'status' => $this->status->value,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
