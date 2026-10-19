<?php

namespace Database\Factories;

use App\Modules\Nodes\Models\SipNode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A node with OpenSIPS and Asterisk (AMI, ARI and realtime) as SIP Deployer describes it, reached
 * over the VPC, with its credentials already fetched.
 *
 * @extends Factory<SipNode>
 */
class SipNodeFactory extends Factory
{
    protected $model = SipNode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $deployerId = fake()->unique()->numberBetween(1, 99999);
        $privateIp = fake()->localIpv4();

        return [
            'deployer_id' => $deployerId,
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
                'asterisk' => ['release' => '22', 'version' => '22.11.0', 'status' => 'installed', 'installed_at' => null, 'features' => ['ami', 'ari', 'realtime']],
            ],
            'endpoints' => [
                'opensips_db' => ['host' => $privateIp, 'port' => 3306, 'database' => 'opensips', 'username' => 'manager'],
                'asterisk_db' => ['host' => $privateIp, 'port' => 3306, 'database' => 'asterisk', 'username' => 'asterisk_manager'],
                'ami' => ['host' => $privateIp, 'port' => 5038, 'username' => 'manager'],
                'ari' => ['host' => $privateIp, 'port' => 8088, 'scheme' => 'http', 'username' => 'manager'],
            ],
            'dialplan' => ['entry_context' => 'from-opensips', 'managed_context' => 'managed', 'opensips_endpoint' => 'opensips'],
            'credentials' => [
                'opensips_db' => ['username' => 'manager', 'password' => fake()->password(20)],
                'asterisk_db' => ['username' => 'asterisk_manager', 'password' => fake()->password(20)],
                'ami' => ['username' => 'manager', 'password' => fake()->password(20)],
                'ari' => ['username' => 'manager', 'password' => fake()->password(20)],
            ],
            'credentials_version' => fake()->regexify('[a-f0-9]{16}'),
            'synced_at' => now(),
        ];
    }

    /**
     * Synced, but the passwords have not been fetched.
     */
    public function withoutCredentials(): static
    {
        return $this->state(fn (): array => ['credentials' => null, 'credentials_version' => null]);
    }

    public function removed(): static
    {
        return $this->state(fn (): array => ['removed_at' => now()]);
    }
}
