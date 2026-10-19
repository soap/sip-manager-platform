<?php

namespace App\Modules\Nodes\Models;

use App\Modules\Nodes\Policies\SipNodePolicy;
use Database\Factories\SipNodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An OpenSIPS and/or Asterisk server provisioned by SIP Deployer. The deployer owns it: this is a
 * copy of its manifest, refreshed by SyncNodes, plus the passwords of this application's users on it.
 *
 * @property int $id
 * @property int $deployer_id
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
 * @property array{entry_context: string, managed_context: string, opensips_endpoint: string}|null $dialplan
 * @property array<string, array{username: string, password: string}>|null $credentials
 * @property string|null $credentials_version Version of the credentials held, as the deployer names it
 * @property array<string, array{ok: bool, message: string}>|null $connection_checks
 * @property Carbon|null $checked_at
 * @property Carbon|null $synced_at
 * @property Carbon|null $removed_at When the deployer stopped listing the node
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'deployer_id', 'manifest_version', 'name', 'hostname', 'sip_domain', 'environment', 'status', 'region',
    'addresses', 'services', 'endpoints', 'dialplan', 'credentials', 'credentials_version',
    'connection_checks', 'checked_at', 'synced_at', 'removed_at',
])]
#[Hidden(['credentials'])]
#[UseFactory(SipNodeFactory::class)]
#[UsePolicy(SipNodePolicy::class)]
class SipNode extends Model
{
    /** @use HasFactory<SipNodeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'deployer_id' => 'integer',
            'manifest_version' => 'integer',
            'addresses' => 'array',
            'services' => 'array',
            'endpoints' => 'array',
            'dialplan' => 'array',
            'credentials' => 'encrypted:array',
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

    public function hasOpenSips(): bool
    {
        return ($this->services['opensips'] ?? null) !== null;
    }

    public function hasAsterisk(): bool
    {
        return ($this->services['asterisk'] ?? null) !== null;
    }

    /**
     * @return list<string>
     */
    public function asteriskFeatures(): array
    {
        return array_values($this->services['asterisk']['features'] ?? []);
    }
}
