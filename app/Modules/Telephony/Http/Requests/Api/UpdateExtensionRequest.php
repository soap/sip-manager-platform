<?php

namespace App\Modules\Telephony\Http\Requests\Api;

use App\Modules\Telephony\Enums\ExtensionStatus;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH of the API: only the fields sent change. The version the client last saw comes in the body
 * or as If-Match (the ETag of GET); without either the change is refused with 428.
 */
class UpdateExtensionRequest extends FormRequest
{
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
            'version' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * From the body, else from If-Match ("3" or W/"3").
     */
    public function expectedVersion(): ?int
    {
        if ($this->has('version')) {
            return $this->integer('version');
        }

        $ifMatch = (string) $this->header('If-Match', '');

        return preg_match('/^(?:W\/)?"(\d+)"$/', trim($ifMatch), $matches) === 1 ? (int) $matches[1] : null;
    }
}
