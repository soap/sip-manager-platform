<?php

namespace App\Modules\Telephony\Models;

use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Telephony\Enums\ExtensionStatus;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\ExtensionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;

/**
 * A tenant's internal number. The number is the tenant's prefix and three digits (e.g. 20101),
 * registered in dial_numbers, and doubles as the SIP username of its phone on the node.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $pbx_node_id
 * @property string $extension_number
 * @property string $display_name
 * @property ExtensionStatus $status
 * @property bool $voicemail_enabled
 * @property string|null $voicemail_pin Shown once when set; Asterisk keeps it in its voicemail table
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['tenant_id', 'pbx_node_id', 'extension_number', 'display_name', 'status', 'voicemail_enabled', 'voicemail_pin', 'version'])]
#[Hidden(['voicemail_pin'])]
#[UseFactory(ExtensionFactory::class)]
class Extension extends Model
{
    /** @use HasFactory<ExtensionFactory> */
    use BelongsToTenant, HasFactory;

    /** The morph name in dial_numbers and config_revisions (registered in AppServiceProvider). */
    public const MORPH = 'extension';

    /** Digits of a new voicemail PIN. */
    public const VOICEMAIL_PIN_LENGTH = 6;

    protected $attributes = [
        'status' => 'active',
        'voicemail_enabled' => false,
        'version' => 1,
    ];

    protected function casts(): array
    {
        return [
            'status' => ExtensionStatus::class,
            'voicemail_enabled' => 'boolean',
            'voicemail_pin' => 'encrypted',
            'version' => 'integer',
        ];
    }

    public static function newVoicemailPin(): string
    {
        return str_pad((string) random_int(0, 10 ** self::VOICEMAIL_PIN_LENGTH - 1), self::VOICEMAIL_PIN_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * The voicemail context of the extension's tenant: "t" and its prefix (plan v0.2 §6.2).
     */
    public function voicemailContext(): string
    {
        return 't'.substr($this->extension_number, 0, 2);
    }

    public function hasVoicemail(): bool
    {
        return $this->voicemail_enabled && $this->voicemail_pin !== null;
    }

    /**
     * @return BelongsTo<PbxNode, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(PbxNode::class, 'pbx_node_id');
    }

    /**
     * @return HasOne<SipCredential, $this>
     */
    public function sipCredential(): HasOne
    {
        return $this->hasOne(SipCredential::class)->withoutGlobalScope(SipCredential::TENANT_SCOPE);
    }

    /**
     * @return MorphOne<DialNumber, $this>
     */
    public function dialNumber(): MorphOne
    {
        return $this->morphOne(DialNumber::class, 'owner')->withoutGlobalScope(DialNumber::TENANT_SCOPE);
    }

    public function isActive(): bool
    {
        return $this->status === ExtensionStatus::Active;
    }
}
