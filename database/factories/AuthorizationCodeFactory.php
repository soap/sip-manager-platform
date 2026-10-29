<?php

namespace Database\Factories;

use App\Modules\Telephony\Enums\AuthorizationCodeStatus;
use App\Modules\Telephony\Enums\CallCategory;
use App\Modules\Telephony\Models\AuthorizationCode;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The code 12345678 of its tenant (or the one given with code()), unlocking other tenants.
 *
 * @extends Factory<AuthorizationCode>
 */
class AuthorizationCodeFactory extends Factory
{
    protected $model = AuthorizationCode::class;

    public const CODE = '12345678';

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'label' => fake()->name(),
            'categories' => [CallCategory::CrossTenant->value],
            'node_hash' => fn (array $attributes): string => AuthorizationCode::nodeHash(Tenant::query()->whereKey($attributes['tenant_id'])->firstOrFail(), self::CODE),
            'status' => AuthorizationCodeStatus::Active,
        ];
    }

    public function code(string $code): static
    {
        return $this->state(fn (): array => [
            'node_hash' => fn (array $attributes): string => AuthorizationCode::nodeHash(Tenant::query()->whereKey($attributes['tenant_id'])->firstOrFail(), $code),
        ]);
    }

    /**
     * @param  list<CallCategory>  $categories
     */
    public function unlocking(array $categories): static
    {
        return $this->state(fn (): array => ['categories' => array_map(fn (CallCategory $category): string => $category->value, $categories)]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['status' => AuthorizationCodeStatus::Disabled]);
    }
}
