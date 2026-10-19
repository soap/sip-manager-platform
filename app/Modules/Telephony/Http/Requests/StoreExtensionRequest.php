<?php

namespace App\Modules\Telephony\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExtensionRequest extends FormRequest
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
        return [
            // two to six digits without a leading zero, inside the tenant's block (checked by the action)
            'extension_number' => ['required', 'string', 'regex:/^[1-9][0-9]{1,5}$/'],
            'display_name' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'extension_number.regex' => 'Use two to six digits, not starting with 0.',
        ];
    }
}
