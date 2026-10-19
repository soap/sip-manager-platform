<?php

namespace App\Modules\Nodes\Http\Requests;

use App\Modules\Nodes\Enums\AssignmentStatus;
use App\Modules\Nodes\Models\SipNode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageAssignments', SipNode::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'number_range_start' => ['required', 'integer', 'min:'.StoreAssignmentRequest::MIN_NUMBER, 'max:'.StoreAssignmentRequest::MAX_NUMBER],
            'number_range_end' => ['required', 'integer', 'gte:number_range_start', 'max:'.StoreAssignmentRequest::MAX_NUMBER],
            'status' => ['required', Rule::enum(AssignmentStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'number_range_end.gte' => 'The last number must not be below the first.',
        ];
    }
}
