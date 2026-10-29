<?php

namespace App\Modules\Telephony\Http\Requests;

use App\Modules\Telephony\Enums\CallCategory;
use App\Modules\Telephony\Enums\CallPermission;
use App\Modules\Telephony\Enums\CrossTenantScope;
use App\Modules\Telephony\Http\Requests\Concerns\ExpectsVersion;
use App\Modules\Telephony\Models\CallingProfile;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A calling profile from the web page or the API: "categories" maps category names to allow, code
 * or deny (a category left out is deny). On PATCH of the API only the fields sent change; the
 * controller fills in the rest from the profile. Whether destination codes exist is checked by
 * ManageCallingProfiles.
 */
class SaveCallingProfileRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $categories = array_map(fn (CallCategory $category): string => $category->value, CallCategory::cases());

        return [
            'name' => [$required, 'string', 'max:100'],
            // empty: a profile that calls nowhere outside the tenant
            'categories' => [$this->isMethod('POST') ? 'present' : 'sometimes', 'array:'.implode(',', $categories)],
            'categories.*' => [Rule::enum(CallPermission::class)],
            'cross_tenant_scope' => ['sometimes', Rule::enum(CrossTenantScope::class)],
            'destinations' => ['sometimes', 'array', 'max:'.CallingProfile::MAX_DESTINATIONS],
            'destinations.*' => ['string', 'digits:4', 'distinct'],
            'version' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'categories.array' => 'Unknown call category.',
            'destinations.max' => 'A profile lists at most '.CallingProfile::MAX_DESTINATIONS.' tenants.',
        ];
    }

    /**
     * @return array<string, CallPermission>
     */
    public function categories(?CallingProfile $current = null): array
    {
        if (! $this->has('categories') && $current !== null) {
            return array_combine(
                array_map(fn (CallCategory $category): string => $category->value, CallCategory::cases()),
                array_map(fn (CallCategory $category): CallPermission => $current->permissionFor($category), CallCategory::cases()),
            );
        }

        return array_map(fn (mixed $permission): CallPermission => CallPermission::from((string) $permission), (array) $this->input('categories', []));
    }

    public function scope(?CallingProfile $current = null): CrossTenantScope
    {
        return $this->enum('cross_tenant_scope', CrossTenantScope::class) ?? $current->cross_tenant_scope ?? CrossTenantScope::All;
    }

    /**
     * @return list<string>
     */
    public function destinationCodes(?CallingProfile $current = null): array
    {
        if (! $this->has('destinations') && $current !== null) {
            return array_values($current->destinations->pluck('code')->map(fn (mixed $code): string => (string) $code)->all());
        }

        return array_values(array_map(strval(...), (array) $this->input('destinations', [])));
    }
}
