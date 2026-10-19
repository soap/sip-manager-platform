<?php

namespace App\Modules\Telephony\Http\Requests;

use App\Modules\Telephony\Enums\ExtensionStatus;
use App\Modules\Telephony\Enums\FailoverType;
use App\Modules\Telephony\Enums\RingStrategy;
use App\Modules\Telephony\Models\RingGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating needs a number; updating takes a status and the version it was edited from. Whether
 * members and failover belong to the tenant is checked by ManageRingGroups.
 */
class SaveRingGroupRequest extends FormRequest
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

        return [
            'number' => $creating ? ['required', 'string', 'regex:/^[2-8][0-9]{4}$/'] : ['prohibited'],
            'name' => ['required', 'string', 'max:100'],
            'strategy' => ['required', Rule::enum(RingStrategy::class)],
            'ring_seconds' => ['required', 'integer', 'min:'.RingGroup::MIN_RING_SECONDS, 'max:'.RingGroup::MAX_RING_SECONDS],
            'members' => ['present', 'array', 'max:'.RingGroup::MAX_MEMBERS],
            'members.*' => ['integer', 'distinct'],
            'failover_type' => ['required', Rule::enum(FailoverType::class)],
            'failover_id' => ['nullable', 'integer', Rule::requiredIf(fn (): bool => $this->input('failover_type') !== FailoverType::Hangup->value)],
            'status' => $creating ? ['prohibited'] : ['required', Rule::enum(ExtensionStatus::class)],
            'version' => $creating ? ['prohibited'] : ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'number.regex' => 'Use five digits: the tenant\'s prefix and three more.',
            'members.max' => 'A ring group has at most '.RingGroup::MAX_MEMBERS.' members.',
            'failover_id.required' => 'Choose where unanswered calls go.',
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
