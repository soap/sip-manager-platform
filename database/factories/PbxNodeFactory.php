<?php

namespace Database\Factories;

use App\Modules\Nodes\Models\PbxNode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A node with OpenSIPS and Asterisk (AMI, ARI and realtime) as SIP Deployer describes it, reached
 * over the VPC, with a secret for each endpoint already fetched.
 *
 * @extends Factory<PbxNode>
 */
class PbxNodeFactory extends Factory
{
    protected $model = PbxNode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $deployerId = fake()->unique()->numberBetween(1, 99999);
        $privateIp = fake()->localIpv4();

        return [
            'deployer_server_id' => $deployerId,
            'manifest_version' => 1,
            'name' => "sip-{$deployerId}",
            'hostname' => "sip{$deployerId}.example.com",
            'sip_domain' => "sip{$deployerId}.example.com",
            'environment' => 'lab',
            'status' => 'active',
            'region' => 'sgp1',
            'addresses' => ['public' => fake()->ipv4(), 'public_ipv6' => null, 'private' => $privateIp, 'management' => $privateIp],
            'services' => [
                'opensips' => ['release' => '3.6', 'version' => null, 'status' => 'installed', 'installed_at' => null, 'transports' => ['udp', 'tcp'], 'sip_port' => 5060],
                'asterisk' => ['release' => '22', 'version' => '22.11.0', 'status' => 'installed', 'installed_at' => null, 'features' => ['ami', 'ari', 'realtime', 'voicemail']],
            ],
            'endpoints' => [
                'opensips_db' => ['host' => $privateIp, 'port' => 3306, 'database' => 'opensips', 'username' => 'manager'],
                'asterisk_db' => ['host' => $privateIp, 'port' => 3306, 'database' => 'asterisk', 'username' => 'asterisk_manager'],
                'ami' => ['host' => $privateIp, 'port' => 5038, 'username' => 'manager'],
                'ari' => ['host' => $privateIp, 'port' => 8088, 'scheme' => 'http', 'username' => 'manager'],
            ],
            'dialplan' => ['entry_context' => 'from-opensips', 'managed_context' => 'managed', 'opensips_endpoint' => 'opensips', 'tenant_routing' => 'domain', 'tenant_code_digits' => 4],
            'credentials_version' => fake()->regexify('[a-f0-9]{16}'),
            'synced_at' => now(),
        ];
    }

    /**
     * Gives a node with a credentials version a secret for each endpoint.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (PbxNode $node): void {
            if ($node->credentials_version === null) {
                return;
            }

            foreach ($node->endpoints as $kind => $endpoint) {
                $node->secrets()->create([
                    'kind' => $kind,
                    'username' => $endpoint['username'],
                    'secret' => fake()->password(20),
                    'fetched_at' => now(),
                ]);
            }
        });
    }

    /**
     * Synced, but the passwords have not been fetched.
     */
    public function withoutCredentials(): static
    {
        return $this->state(fn (): array => ['credentials_version' => null]);
    }

    public function removed(): static
    {
        return $this->state(fn (): array => ['removed_at' => now()]);
    }
}
