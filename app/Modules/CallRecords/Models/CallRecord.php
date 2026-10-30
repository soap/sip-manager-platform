<?php

namespace App\Modules\CallRecords\Models;

use App\Modules\CallRecords\Enums\CallStatus;
use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Telephony\Models\AuthorizationCode;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A copy of one CDR row of a node (plan §7), with the tenants on both ends found from the tenant
 * codes in src and dst (plan D6). A call between two tenants belongs to both, so it is not scoped
 * to one tenant: query it with forTenant().
 *
 * @property int $id
 * @property int $pbx_node_id
 * @property string $uniqueid
 * @property int $sequence
 * @property string|null $linkedid
 * @property string|null $caller_tenant_id
 * @property string|null $callee_tenant_id
 * @property string|null $from_number
 * @property string|null $to_number
 * @property CallStatus $status
 * @property int|null $authorization_code_id
 * @property Carbon|null $started_at
 * @property Carbon|null $answered_at
 * @property Carbon $ended_at
 * @property int $duration
 * @property int $billsec
 * @property string|null $src
 * @property string|null $dst
 * @property string|null $clid
 * @property string|null $channel
 * @property string|null $dstchannel
 * @property string|null $lastapp
 * @property string|null $lastdata
 * @property string|null $disposition
 * @property string|null $accountcode
 * @property string|null $userfield
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'pbx_node_id', 'uniqueid', 'sequence', 'linkedid', 'caller_tenant_id', 'callee_tenant_id',
    'from_number', 'to_number', 'status', 'authorization_code_id', 'started_at', 'answered_at',
    'ended_at', 'duration', 'billsec', 'src', 'dst', 'clid', 'channel', 'dstchannel', 'lastapp',
    'lastdata', 'disposition', 'accountcode', 'userfield',
])]
class CallRecord extends Model
{
    use MassPrunable;

    /** Months a call is kept (plan §7). */
    public const RETENTION_MONTHS = 13;

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'status' => CallStatus::class,
            'started_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration' => 'integer',
            'billsec' => 'integer',
        ];
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('ended_at', '<', now()->subMonths(self::RETENTION_MONTHS));
    }

    /**
     * Calls the tenant placed or received.
     *
     * @param  Builder<static>  $query
     */
    public function scopeForTenant(Builder $query, string $tenantId): void
    {
        $query->where(fn (Builder $query) => $query->where('caller_tenant_id', $tenantId)->orWhere('callee_tenant_id', $tenantId));
    }

    /**
     * @return BelongsTo<PbxNode, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(PbxNode::class, 'pbx_node_id');
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function callerTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'caller_tenant_id');
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function calleeTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'callee_tenant_id');
    }

    /**
     * @return BelongsTo<AuthorizationCode, $this>
     */
    public function authorizationCode(): BelongsTo
    {
        return $this->belongsTo(AuthorizationCode::class)->withoutGlobalScopes();
    }
}
