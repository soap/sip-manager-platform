<?php

namespace App\Modules\Telephony\Http\Requests;

use App\Modules\Telephony\Enums\AuthorizationCodeStatus;
use App\Modules\Telephony\Enums\CallCategory;
use App\Modules\Telephony\Http\Requests\Concerns\ExpectsVersion;
use App\Modules\Telephony\Models\AuthorizationCode;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\Rule;

/**
 * An authorization code from the web page or the API. Creating may give the digits ("code"), else
 * the system makes them up; updating never changes them (reset does). On PATCH of the API only the
 * fields sent change.
 */
class SaveAuthorizationCodeRequest extends FormRequest
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
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'label' => [$required, 'string', 'max:100'],
            'categories' => [$required, 'array', 'min:1'],
            'categories.*' => [Rule::enum(CallCategory::class), 'distinct'],
            'expires_at' => ['sometimes', 'nullable', 'date', ...($creating ? ['after:now'] : [])],
            'user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tenant_memberships', 'user_id')->where('tenant_id', app(TenantContext::class)->id())],
            'code' => $creating ? ['sometimes', 'nullable', 'string', 'regex:/^\d{'.AuthorizationCode::MIN_LENGTH.','.AuthorizationCode::MAX_LENGTH.'}$/'] : ['prohibited'],
            'status' => $creating ? ['prohibited'] : ['sometimes', Rule::enum(AuthorizationCodeStatus::class)],
            'version' => $creating ? ['prohibited'] : ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'A code is '.AuthorizationCode::MIN_LENGTH.' to '.AuthorizationCode::MAX_LENGTH.' digits.',
            'categories.min' => 'Choose at least one category the code unlocks.',
            'user_id.exists' => 'Choose a member of this tenant.',
        ];
    }

    /**
     * @return list<CallCategory>
     */
    public function categories(?AuthorizationCode $current = null): array
    {
        if (! $this->has('categories') && $current !== null) {
            return $current->categoryCases();
        }

        return array_values(array_map(fn (mixed $category): CallCategory => CallCategory::from((string) $category), (array) $this->input('categories', [])));
    }

    public function expiresAt(?AuthorizationCode $current = null): ?CarbonInterface
    {
        if (! $this->has('expires_at')) {
            return $current?->expires_at;
        }

        return $this->filled('expires_at') ? Date::parse($this->string('expires_at')->toString()) : null;
    }

    public function userId(?AuthorizationCode $current = null): ?int
    {
        if (! $this->has('user_id')) {
            return $current?->user_id;
        }

        return $this->filled('user_id') ? $this->integer('user_id') : null;
    }
}
