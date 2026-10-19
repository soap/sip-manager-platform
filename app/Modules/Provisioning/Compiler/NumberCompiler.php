<?php

namespace App\Modules\Provisioning\Compiler;

use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Nodes\Models\TenantNumberRange;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Provisioning\Exceptions\ProvisioningRejected;
use App\Modules\Telephony\Models\DialNumber;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Tenancy\Models\Tenant;

/**
 * Compiles the desired state of one number on a node, as it is now (plan v0.2 §6.2), so applying
 * revisions out of order still ends with the latest state.
 *
 * An active extension 20101 of the tenant with prefix 20 becomes:
 * - subscriber 20101@<sip_domain> with its ha1, so its phone can register and call;
 * - exten 20101 in "managed": 1 GotoIf caller not 20xxx → denied, 2 Dial(PJSIP/20101@opensips,30),
 *   3 Hangup() — only the tenant's own phones reach it (D1).
 * A disabled extension, or one whose tenant or assignment is suspended, loses its subscriber and its
 * exten sends every caller to denied. A number nobody owns gets no rows at all.
 */
class NumberCompiler
{
    /** Seconds a phone rings before the call gives up. */
    public const RING_SECONDS = 30;

    /** Shared by every tenant: where refused calls go. */
    public const DENIED_EXTEN = 'denied';

    /**
     * @throws ProvisioningRejected when the number cannot be written to this node
     */
    public function compile(PbxNode $node, string $number): CompiledNumber
    {
        $dialNumber = DialNumber::withoutTenantScope()->where('pbx_node_id', $node->id)->where('number', $number)->first();

        $owner = $dialNumber?->owner_type === Extension::MORPH
            ? Extension::withoutTenantScope()->find($dialNumber->owner_id)
            : null;

        if (! $owner instanceof Extension) {
            return new CompiledNumber($number, 'unused', null, []);
        }

        $range = TenantNumberRange::query()->where('tenant_id', $owner->tenant_id)->where('pbx_node_id', $node->id)->first();

        if ($range === null || ! $range->contains($number)) {
            throw ProvisioningRejected::because('number_outside_prefix', "Number {$number} is not in its tenant's prefix on this node.");
        }

        if (! $this->inService($owner)) {
            return new CompiledNumber($number, 'disabled', null, [
                ['priority' => 1, 'app' => 'Goto', 'appdata' => self::DENIED_EXTEN.',1'],
            ]);
        }

        $credential = $owner->sipCredential;

        if ($credential === null) {
            throw ProvisioningRejected::because('missing_credential', "Extension {$number} has no SIP credential.");
        }

        $domain = (string) ($node->sip_domain ?? $node->addresses['public'] ?? '');

        if ($domain === '') {
            throw ProvisioningRejected::because('missing_sip_domain', "Node {$node->name} has no SIP domain.");
        }

        return new CompiledNumber($number, 'active', [
            'username' => $credential->sip_username,
            'domain' => $domain,
            'ha1' => $credential->ha1($domain),
            'ha1_sha256' => $credential->ha1Sha256($domain),
        ], [
            ['priority' => 1, 'app' => 'GotoIf', 'appdata' => sprintf('$["${CALLERID(num):0:%d}" != "%s"]?%s,1', strlen($range->prefix), $range->prefix, self::DENIED_EXTEN)],
            ['priority' => 2, 'app' => 'Dial', 'appdata' => sprintf('PJSIP/%s@%s,%d', $number, $node->dialplan['opensips_endpoint'] ?? 'opensips', self::RING_SECONDS)],
            ['priority' => 3, 'app' => 'Hangup', 'appdata' => ''],
        ]);
    }

    /**
     * The shared exten refused calls go to: "not in service", then hang up with cause 21 (rejected).
     *
     * @return list<array{priority: int, app: string, appdata: string}>
     */
    public function denied(): array
    {
        return [
            ['priority' => 1, 'app' => 'Playback', 'appdata' => 'ss-noservice'],
            ['priority' => 2, 'app' => 'Hangup', 'appdata' => '21'],
        ];
    }

    private function inService(Extension $extension): bool
    {
        $tenant = Tenant::query()->find($extension->tenant_id);
        $assignment = TenantPbxAssignment::query()->where('tenant_id', $extension->tenant_id)->first();

        return $extension->isActive()
            && $tenant?->isActive() === true
            && $assignment?->isActive() === true
            && $assignment->pbx_node_id === $extension->pbx_node_id;
    }
}
