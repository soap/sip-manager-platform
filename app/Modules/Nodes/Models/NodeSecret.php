<?php

namespace App\Modules\Nodes\Models;

use App\Modules\Nodes\Enums\NodeSecretKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The username and password of this application's user on one endpoint of a node, as SIP
 * Deployer hands them out. The password is encrypted with the app key and never leaves the
 * server: not in a page, a log or the audit log.
 *
 * @property int $id
 * @property int $pbx_node_id
 * @property NodeSecretKind $kind
 * @property string $username
 * @property string $secret
 * @property Carbon $fetched_at
 * @property Carbon|null $rotated_at When the deployer last handed out a different password
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['pbx_node_id', 'kind', 'username', 'secret', 'fetched_at', 'rotated_at'])]
#[Hidden(['secret'])]
class NodeSecret extends Model
{
    protected function casts(): array
    {
        return [
            'kind' => NodeSecretKind::class,
            'secret' => 'encrypted',
            'fetched_at' => 'datetime',
            'rotated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PbxNode, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(PbxNode::class, 'pbx_node_id');
    }
}
