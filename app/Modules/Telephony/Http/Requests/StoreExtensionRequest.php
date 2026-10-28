<?php

namespace App\Modules\Telephony\Http\Requests;

use App\Modules\Telephony\NumberAllocator;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

class StoreExtensionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // the tenant of the page or of the API token
        $tenant = app(TenantContext::class)->tenant();

        return $tenant !== null && ($this->user()?->can('manageExtensions', $tenant) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'extension_number' => ['required', 'string', 'regex:'.NumberAllocator::PATTERN],
            'display_name' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'extension_number.regex' => NumberAllocator::RULE_MESSAGE,
        ];
    }
}
