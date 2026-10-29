<?php

namespace App\Modules\Telephony\Http\Resources;

use App\Modules\Telephony\Enums\CallCategory;
use App\Modules\Telephony\Models\CallingProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A calling profile: every category with allow, code or deny, and the other tenants it may call by
 * code only (their names show in the directory, once they take our calls).
 *
 * @mixin CallingProfile
 */
class CallingProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'categories' => array_combine(
                array_map(fn (CallCategory $category): string => $category->value, CallCategory::cases()),
                array_map(fn (CallCategory $category): string => $this->permissionFor($category)->value, CallCategory::cases()),
            ),
            'cross_tenant_scope' => [
                'scope' => $this->cross_tenant_scope->value,
                'tenants' => $this->destinations->pluck('code')->sort()->values()->map(fn (mixed $code): string => (string) $code)->all(),
            ],
            'is_default' => $this->is_default,
            'extensions_count' => $this->whenCounted('extensions'),
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
