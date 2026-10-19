<?php

namespace App\Modules\Nodes\Http\Requests;

use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Nodes\Models\TenantNumberRange;
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
            'prefix' => self::prefixRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::prefixMessages();
    }

    /**
     * @return list<string>
     */
    public static function prefixRules(): array
    {
        // 20–89: never 0, 1 or 9 first (emergency, hotline and outbound numbers)
        return ['required', 'string', 'regex:/^[2-8][0-9]$/'];
    }

    /**
     * @return array<string, string>
     */
    public static function prefixMessages(): array
    {
        return ['prefix.regex' => 'Use a two-digit prefix from '.TenantNumberRange::FIRST_PREFIX.' to '.TenantNumberRange::LAST_PREFIX.'.'];
    }
}
