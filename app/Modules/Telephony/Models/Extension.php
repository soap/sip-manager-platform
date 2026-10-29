<?php

namespace App\Modules\Telephony\Models;

use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Provisioning\Models\ProvisioningOperation;
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
 * A tenant's internal number (e.g. 101), registered in dial_numbers. It doubles as the SIP username
 * of its phone in the tenant's SIP domain, so other tenants may have the same number.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $pbx_node_id
 * @property string $extension_number
 * @property string $display_name
 * @property ExtensionStatus $status
 * @property bool $voicemail_enabled
 * @property string|null $voicemail_pin Shown once when set; Asterisk keeps it in its voicemail table
 * @property int|null $calling_profile_id Null: the tenant's default profile
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['tenant_id', 'pbx_node_id', 'extension_number', 'display_name', 'status', 'voicemail_enabled', 'voicemail_pin', 'calling_profile_id', 'version'])]
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
     * Its own calling profile; see effectiveCallingProfile() for the one that applies.
     *
     * @return BelongsTo<CallingProfile, $this>
     */
    public function callingProfile(): BelongsTo
    {
        return $this->belongsTo(CallingProfile::class)->withoutGlobalScope(CallingProfile::TENANT_SCOPE);
    }

    /**
     * Its own profile, else the tenant's default; null only for a tenant without profiles, whose
     * extensions may then call within the tenant only.
     */
    public function effectiveCallingProfile(): ?CallingProfile
    {
        return $this->callingProfile ?? CallingProfile::withoutTenantScope()
            ->where('tenant_id', $this->tenant_id)
            ->where('is_default', true)
            ->first();
    }

    /**
     * @return MorphOne<DialNumber, $this>
     */
    public function dialNumber(): MorphOne
    {
        return $this->morphOne(DialNumber::class, 'owner')->withoutGlobalScope(DialNumber::TENANT_SCOPE);
    }

    /**
     * The newest provisioning operation of this extension: whether the node has it yet.
     *
     * @return MorphOne<ProvisioningOperation, $this>
     */
    public function latestOperation(): MorphOne
    {
        return $this->morphOne(ProvisioningOperation::class, 'aggregate')->latestOfMany('id');
    }

    public function isActive(): bool
    {
        return $this->status === ExtensionStatus::Active;
    }
}
