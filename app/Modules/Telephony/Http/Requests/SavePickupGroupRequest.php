<?php

namespace App\Modules\Telephony\Http\Requests;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Updating takes the version it was edited from. Whether members belong to the tenant and to no
 * other group is checked by ManagePickupGroups.
 */
class SavePickupGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageExtensions', $this->route('tenant')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        /** @var Tenant $tenant */
        $tenant = $this->route('tenant');

        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('pickup_groups', 'name')
                    ->where('tenant_id', $tenant->id)
                    ->when(! $creating, fn ($rule) => $rule->ignore((int) $this->route('pickupGroup'))),
            ],
            'members' => ['present', 'array'],
            'members.*' => ['integer', 'distinct'],
            'version' => $creating ? ['prohibited'] : ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'There is already a pickup group with this name.',
        ];
    }

    /**
     * @return list<int>
     */
    public function memberIds(): array
    {
        return array_values(array_map(intval(...), (array) $this->input('members', [])));
    }
}
