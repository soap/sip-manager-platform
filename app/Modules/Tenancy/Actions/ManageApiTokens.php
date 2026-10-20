<?php

namespace App\Modules\Tenancy\Actions;

use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Tenancy\Enums\ApiAbility;
use App\Modules\Tenancy\Models\ApiToken;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Issues and revokes the API tokens of the tenant in the context. The plain token is shown once;
 * only its SHA-256 hash is stored.
 */
class ManageApiTokens
{
    public function __construct(private Audit $audit, private TenantContext $context) {}

    /**
     * @param  list<ApiAbility>  $abilities
     * @return array{token: ApiToken, plain_text: string}
     */
    public function issue(User $user, string $name, array $abilities, ?CarbonInterface $expiresAt = null): array
    {
        return DB::transaction(function () use ($user, $name, $abilities, $expiresAt): array {
            $new = $user->createToken($name, array_map(fn (ApiAbility $ability): string => $ability->value, $abilities), $expiresAt);

            /** @var ApiToken $token */
            $token = $new->accessToken;
            $token->forceFill(['tenant_id' => $this->context->id()])->save();

            $this->audit->record('api_token.created', $token, ['name' => $name, 'abilities' => $token->abilities, 'expires_at' => $expiresAt?->toIso8601String()]);

            return ['token' => $token, 'plain_text' => $new->plainTextToken];
        });
    }

    public function revoke(ApiToken $token): void
    {
        $token->delete();

        $this->audit->record('api_token.revoked', $token, ['name' => $token->name]);
    }

    /**
     * The tenant's tokens, newest first.
     *
     * @return Collection<int, ApiToken>
     */
    public function list(): Collection
    {
        return ApiToken::query()
            ->where('tenant_id', $this->context->id())
            ->with('tokenable')
            ->latest('id')
            ->get();
    }

    public function find(int $id): ApiToken
    {
        return ApiToken::query()->where('tenant_id', $this->context->id())->findOrFail($id);
    }

    /**
     * Revokes a former member's tokens of the tenant in the context.
     */
    public function revokeAllOf(User $user): void
    {
        ApiToken::query()
            ->where('tenant_id', $this->context->id())
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->getKey())
            ->delete();
    }
}
