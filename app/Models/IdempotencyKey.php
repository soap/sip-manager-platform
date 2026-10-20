<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An Idempotency-Key a tenant's API client sent with a POST, and the answer it got, so a repeat
 * gets the same answer instead of a second change. Secrets in the answer are not kept (see
 * the Idempotent middleware).
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $key
 * @property string $request_hash
 * @property int|null $response_status
 * @property array<string, mixed>|null $response_body
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['tenant_id', 'key', 'request_hash', 'response_status', 'response_body', 'expires_at'])]
class IdempotencyKey extends Model
{
    use MassPrunable;

    /** Hours a key is remembered. */
    public const LIFETIME_HOURS = 24;

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'response_body' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now());
    }

    public function isComplete(): bool
    {
        return $this->response_status !== null;
    }
}
