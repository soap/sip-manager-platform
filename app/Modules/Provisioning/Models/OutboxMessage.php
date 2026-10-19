<?php

namespace App\Modules\Provisioning\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A message written in the same transaction as the change it announces; the relay dispatches it.
 *
 * @property int $id
 * @property string $topic
 * @property array<string, mixed> $payload
 * @property Carbon $available_at
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['topic', 'payload', 'available_at', 'dispatched_at'])]
class OutboxMessage extends Model
{
    public const APPLY_REVISION = 'provisioning.apply_revision';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'available_at' => 'datetime',
            'dispatched_at' => 'datetime',
        ];
    }
}
