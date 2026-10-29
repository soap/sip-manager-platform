<?php

namespace App\Modules\Telephony\Http\Requests\Api;

use App\Modules\Telephony\Enums\ExtensionStatus;
use App\Modules\Telephony\Http\Requests\Concerns\ExpectsVersion;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH of the API: only the fields sent change. The version the client last saw comes in the body
 * or as If-Match (the ETag of GET); without either the change is refused with 428.
 * calling_profile_id null gives the extension the tenant's default profile.
 */
class UpdateExtensionRequest extends FormRequest
{
    use ExpectsVersion;

    public function authorize(): bool
    {
        $tenant = app(TenantContext::class)->tenant();

        return $tenant !== null && ($this->user()?->can('manageExtensions', $tenant) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['sometimes', 'required', 'string', 'max:100'],
            'status' => ['sometimes', 'required', Rule::enum(ExtensionStatus::class)],
            'calling_profile_id' => ['sometimes', 'nullable', 'integer'],
            'version' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
