<?php

namespace App\Modules\Telephony\Models;

use App\Models\User;
use App\Modules\Telephony\Enums\AuthorizationCodeStatus;
use App\Modules\Telephony\Enums\CallCategory;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Models\Tenant;
use Database\Factories\AuthorizationCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A code of the tenant that lets a caller past a category their calling profile sets to "code"
 * (plan D7): the caller dials, hears "please enter your password", enters the code and #. Any
 * phone of the tenant may use it; the CDR names the code by its id.
 *
 * The code is shown once and never stored. The node only needs node_hash, the md5 of the tenant's
 * auth_salt and the code, as the name of the exten <code>a<node_hash> that the dialplan looks for.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $label
 * @property int|null $user_id
 * @property list<string> $categories category values it unlocks
 * @property string $node_hash
 * @property AuthorizationCodeStatus $status
 * @property Carbon|null $expires_at
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['tenant_id', 'label', 'user_id', 'categories', 'node_hash', 'status', 'expires_at', 'version'])]
#[Hidden(['node_hash'])]
#[UseFactory(AuthorizationCodeFactory::class)]
class AuthorizationCode extends Model
{
    /** @use HasFactory<AuthorizationCodeFactory> */
    use BelongsToTenant, HasFactory;

    public const MORPH = 'authorization_code';

    public const MIN_LENGTH = 6;

    /** Read() on the node takes at most this many digits. */
    public const MAX_LENGTH = 12;

    /** Digits of a code the system makes up. */
    public const NEW_LENGTH = 8;

    /** Each is a row in the tenant's dialplan, rewritten on every change. */
    public const MAX_PER_TENANT = 500;

    protected $attributes = [
        'categories' => '[]',
        'status' => 'active',
        'version' => 1,
    ];

    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'status' => AuthorizationCodeStatus::class,
            'expires_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public static function newCode(): string
    {
        return str_pad((string) random_int(0, 10 ** self::NEW_LENGTH - 1), self::NEW_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * The name the code has in the tenant's dialplan (without the "<code>a" before it).
     */
    public static function nodeHash(Tenant $tenant, string $code): string
    {
        return md5($tenant->auth_salt.$code);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether a caller can use it now: active and not past its expiry.
     */
    public function isUsable(): bool
    {
        return $this->status === AuthorizationCodeStatus::Active
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function unlocks(CallCategory $category): bool
    {
        return in_array($category->value, $this->categories, true);
    }

    /**
     * @return list<CallCategory>
     */
    public function categoryCases(): array
    {
        return array_values(array_filter(array_map(CallCategory::tryFrom(...), $this->categories)));
    }
}
