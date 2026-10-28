<?php

namespace App\Modules\Nodes\Models;

use App\Modules\Nodes\Enums\NodeSecretKind;
use App\Modules\Nodes\Policies\PbxNodePolicy;
use App\Modules\Tenancy\Models\Tenant;
use Database\Factories\PbxNodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An OpenSIPS and/or Asterisk server provisioned by SIP Deployer. The deployer owns it: this is a
 * copy of its manifest, refreshed by SyncNodes. The passwords of this application's users on it
 * are its NodeSecrets.
 *
 * @property int $id
 * @property int $deployer_server_id
 * @property int $manifest_version
 * @property string $name
 * @property string|null $hostname
 * @property string|null $sip_domain
 * @property string $environment lab, pilot or production
 * @property string $status creating, bootstrapping, active, failed, off or deleting
 * @property string|null $region
 * @property array{public: string|null, public_ipv6: string|null, private: string|null, management: string|null} $addresses
 * @property array{opensips: array<string, mixed>|null, asterisk: array<string, mixed>|null} $services
 * @property array<string, array<string, mixed>> $endpoints opensips_db, asterisk_db, ami and ari, without passwords
 * @property array{entry_context: string, managed_context: string, opensips_endpoint: string, tenant_routing?: string, tenant_code_digits?: int}|null $dialplan
 * @property string|null $credentials_version Version of the secrets held, as the deployer names it
 * @property array<string, array{ok: bool, message: string}>|null $connection_checks
 * @property Carbon|null $checked_at
 * @property Carbon|null $synced_at
 * @property Carbon|null $removed_at When the deployer stopped listing the node
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'deployer_server_id', 'manifest_version', 'name', 'hostname', 'sip_domain', 'environment', 'status', 'region',
    'addresses', 'services', 'endpoints', 'dialplan', 'credentials_version',
    'connection_checks', 'checked_at', 'synced_at', 'removed_at',
])]
#[UseFactory(PbxNodeFactory::class)]
#[UsePolicy(PbxNodePolicy::class)]
class PbxNode extends Model
{
    /** @use HasFactory<PbxNodeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'deployer_server_id' => 'integer',
            'manifest_version' => 'integer',
            'addresses' => 'array',
            'services' => 'array',
            'endpoints' => 'array',
            'dialplan' => 'array',
            'connection_checks' => 'array',
            'checked_at' => 'datetime',
            'synced_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    /**
     * Nodes the deployer still lists.
     *
     * @param  Builder<self>  $query
     */
    public function scopeListed(Builder $query): void
    {
        $query->whereNull('removed_at');
    }

    /**
     * The tenants on the node and their blocks of numbers.
     *
     * @return HasMany<TenantPbxAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(TenantPbxAssignment::class);
    }

    /**
     * @return HasMany<NodeSecret, $this>
     */
    public function secrets(): HasMany
    {
        return $this->hasMany(NodeSecret::class);
    }

    /**
     * Username and password per endpoint (opensips_db, asterisk_db, ami, ari), from the secrets
     * as loaded; call load('secrets') to see a change made elsewhere.
     *
     * @return array<string, array{username: string, password: string}>
     */
    public function credentials(): array
    {
        $order = array_flip(array_column(NodeSecretKind::cases(), 'value'));

        return $this->secrets
            ->sortBy(fn (NodeSecret $secret): int => $order[$secret->kind->value])
            ->mapWithKeys(fn (NodeSecret $secret): array => [$secret->kind->value => ['username' => $secret->username, 'password' => $secret->secret]])
            ->all();
    }

    public function hasOpenSips(): bool
    {
        return ($this->services['opensips'] ?? null) !== null;
    }

    public function hasAsterisk(): bool
    {
        return ($this->services['asterisk'] ?? null) !== null;
    }

    /**
     * Whether Asterisk on the node keeps voicemail boxes in its realtime database.
     */
    public function hasVoicemail(): bool
    {
        return in_array('voicemail', $this->asteriskFeatures(), true);
    }

    /**
     * @return list<string>
     */
    public function asteriskFeatures(): array
    {
        return array_values($this->services['asterisk']['features'] ?? []);
    }

    /**
     * Whether the node serves a SIP domain per tenant (plan D6): OpenSIPS knows the tenants from
     * its domain table and Asterisk sees every number as <tenant code><number>. Nodes installed
     * before that serve one shared domain and cannot take tenants' numbers.
     */
    public function hasTenantDomains(): bool
    {
        return ($this->dialplan['tenant_routing'] ?? null) === 'domain'
            && (int) ($this->dialplan['tenant_code_digits'] ?? 0) === Tenant::CODE_DIGITS;
    }

    /**
     * Where a phone of the tenant registers: the tenant's SIP domain (also the realm), and the
     * node itself as outbound proxy for phones whose domain is not in DNS yet.
     *
     * @return array{domain: string, proxy: string|null, port: int, transports: list<string>}
     */
    public function sipServer(Tenant $tenant): array
    {
        return [
            'domain' => $tenant->sip_domain,
            'proxy' => $this->sip_domain ?? $this->addresses['public'] ?? null,
            'port' => (int) ($this->services['opensips']['sip_port'] ?? 5060),
            'transports' => array_values($this->services['opensips']['transports'] ?? ['udp']),
        ];
    }
}
