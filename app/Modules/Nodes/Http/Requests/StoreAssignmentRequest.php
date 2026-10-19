<?php

namespace App\Modules\Nodes\Http\Requests;

use App\Modules\Nodes\Models\SipNode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssignmentRequest extends FormRequest
{
    /** Extension numbers have two to six digits. */
    public const MIN_NUMBER = 10;

    public const MAX_NUMBER = 999999;

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
            'tenant_id' => ['required', 'string', Rule::exists('tenants', 'id')],
            'number_range_start' => ['required', 'integer', 'min:'.self::MIN_NUMBER, 'max:'.self::MAX_NUMBER],
            'number_range_end' => ['required', 'integer', 'gte:number_range_start', 'max:'.self::MAX_NUMBER],
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
