<?php

namespace App\Modules\Nodes\Models;

use App\Modules\Tenancy\Models\Tenant;
use Database\Factories\TenantNumberRangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A tenant's numbers on a node: a two-digit prefix PP followed by a three-digit extension EEE,
 * e.g. prefix 20 owns 20000–20999. Tenants on a node share its SIP domain and its "managed"
 * dialplan context, so the prefix is unique per node; it also tells the dialplan which tenant
 * a caller belongs to.
 *
 * Prefixes start with 2–8: 0, 1, 9 and * are kept for Thai emergency and hotline numbers
 * (191, 1669, 1xxx), outbound prefixes and feature codes.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $pbx_node_id
 * @property string $prefix
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['tenant_id', 'pbx_node_id', 'prefix'])]
#[UseFactory(TenantNumberRangeFactory::class)]
class TenantNumberRange extends Model
{
    /** @use HasFactory<TenantNumberRangeFactory> */
    use HasFactory;

    public const FIRST_PREFIX = 20;

    public const LAST_PREFIX = 89;

    /** Digits after the prefix. */
    public const EXTENSION_DIGITS = 3;

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<PbxNode, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(PbxNode::class, 'pbx_node_id');
    }

    public static function isValidPrefix(string $prefix): bool
    {
        return preg_match('/^\d{2}$/', $prefix) === 1
            && (int) $prefix >= self::FIRST_PREFIX
            && (int) $prefix <= self::LAST_PREFIX;
    }

    /**
     * The lowest prefix no tenant uses on the node, or null when all are taken.
     */
    public static function nextFreePrefix(int $nodeId): ?string
    {
        $taken = self::query()->where('pbx_node_id', $nodeId)->pluck('prefix')->flip();

        foreach (range(self::FIRST_PREFIX, self::LAST_PREFIX) as $prefix) {
            if (! $taken->has((string) $prefix)) {
                return (string) $prefix;
            }
        }

        return null;
    }

    public function contains(string $number): bool
    {
        return preg_match('/^'.$this->prefix.'\d{'.self::EXTENSION_DIGITS.'}$/', $number) === 1;
    }

    public function firstNumber(): string
    {
        return $this->prefix.str_repeat('0', self::EXTENSION_DIGITS);
    }

    public function lastNumber(): string
    {
        return $this->prefix.str_repeat('9', self::EXTENSION_DIGITS);
    }

    public function label(): string
    {
        return "{$this->firstNumber()}–{$this->lastNumber()}";
    }
}
