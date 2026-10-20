<?php

namespace App\Modules\Tenancy\Http\Requests;

use App\Modules\Tenancy\Enums\ApiAbility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApiTokenRequest extends FormRequest
{
    /** Days a new token may live; null = until revoked. */
    public const EXPIRY_DAYS = [30, 90, 365];

    public function authorize(): bool
    {
        return $this->user()?->can('manageApiTokens', $this->route('tenant')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'ability' => ['required', Rule::enum(ApiAbility::class)],
            'expires_in_days' => ['nullable', 'integer', Rule::in(self::EXPIRY_DAYS)],
        ];
    }
}
