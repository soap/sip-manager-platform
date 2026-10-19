<?php

namespace App\Modules\Telephony\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The SIP account a phone registers with: the extension number as username and a random password,
 * shown once when it is created or rotated and otherwise kept encrypted (plan D4). The node only
 * ever gets the digest, ha1 = md5("username:realm:password").
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $extension_id
 * @property int $pbx_node_id
 * @property string $sip_username
 * @property string $secret
 * @property Carbon|null $rotated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['tenant_id', 'extension_id', 'pbx_node_id', 'sip_username', 'secret', 'rotated_at'])]
#[Hidden(['secret'])]
class SipCredential extends Model
{
    use BelongsToTenant;

    public const PASSWORD_LENGTH = 20;

    /** Letters and digits people do not mix up when typing into a phone: no I, l, 1, O, o or 0. */
    public const PASSWORD_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'rotated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Extension, $this>
     */
    public function extension(): BelongsTo
    {
        return $this->belongsTo(Extension::class);
    }

    /**
     * Letters and digits only, so phones and their web forms take them without escaping, and none
     * that look alike, since people type them into phones. 20 of 56 characters: about 116 bits.
     */
    public static function newPassword(): string
    {
        $last = strlen(self::PASSWORD_ALPHABET) - 1;
        $password = '';

        for ($i = 0; $i < self::PASSWORD_LENGTH; $i++) {
            $password .= self::PASSWORD_ALPHABET[random_int(0, $last)];
        }

        return $password;
    }

    /**
     * The MD5 digest OpenSIPS checks (auth_db password_column = ha1).
     */
    public function ha1(string $realm): string
    {
        return md5("{$this->sip_username}:{$realm}:{$this->secret}");
    }

    public function ha1Sha256(string $realm): string
    {
        return hash('sha256', "{$this->sip_username}:{$realm}:{$this->secret}");
    }
}
