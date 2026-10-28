<?php

namespace App\Modules\Nodes\Http\Requests;

use App\Modules\Nodes\Models\PbxNode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageAssignments', PbxNode::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tenant_id' => ['required', 'string', Rule::exists('tenants', 'id')],
        ];
    }
}
