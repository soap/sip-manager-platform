<?php

namespace App\Modules\Telephony\Http\Requests;

use App\Modules\Telephony\Enums\ExtensionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExtensionRequest extends FormRequest
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
            'display_name' => ['required', 'string', 'max:100'],
            'status' => ['required', Rule::enum(ExtensionStatus::class)],
        ];
    }
}
