<?php

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A Sanctum token of a user that works in one tenant only. Requests made with it act as that user,
 * with the role the user has in the tenant at the time, so a demoted or removed member's tokens
 * lose their power with them.
 *
 * @property string|null $tenant_id
 */
class ApiToken extends PersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
