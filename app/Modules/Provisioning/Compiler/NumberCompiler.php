<?php

namespace App\Modules\Provisioning\Compiler;

use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Nodes\Models\TenantNumberRange;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Provisioning\Exceptions\ProvisioningRejected;
use App\Modules\Telephony\Enums\FailoverType;
use App\Modules\Telephony\Enums\RingStrategy;
use App\Modules\Telephony\Models\DialNumber;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Telephony\Models\RingGroup;
use App\Modules\Tenancy\Models\Tenant;

/**
 * Compiles the desired state of one number on a node, as it is now (plan v0.2 §6.2), so applying
 * revisions out of order still ends with the latest state. Every exten a tenant owns in "managed"
 * starts by sending callers outside the tenant's prefix to denied (D1).
 *
 * An active extension 20101 of the tenant with prefix 20 becomes:
 * - subscriber 20101@<sip_domain> with its ha1, so its phone can register and call;
 * - exten 20101: 1 GotoIf caller not 20xxx → denied, 2 Dial(PJSIP/20101@opensips,30), 3 Hangup().
 *
 * An active ring group 20500 (D5) becomes exten 20500: 1 the same GotoIf, then one Dial of all
 * active members (ring_all) or one Dial per active member (sequential), then its failover: Goto
 * another number of the tenant, or Hangup(). Dial only returns when nobody answered, so the next
 * row runs only for an unanswered call.
 *
 * Anything disabled, or of a suspended tenant or assignment, loses its subscriber and sends every
 * caller to denied. A number nobody owns gets no rows at all.
 */
class NumberCompiler
{
    /** Seconds a phone rings before the call gives up. */
    public const RING_SECONDS = 30;

    /** Shared by every tenant: where refused calls go. */
    public const DENIED_EXTEN = 'denied';

    /** The width of extensions.appdata in the Asterisk realtime schema. */
    public const MAX_APPDATA = 256;

    /**
     * @throws ProvisioningRejected when the number cannot be written to this node
     */
    public function compile(PbxNode $node, string $number): CompiledNumber
    {
        $dialNumber = DialNumber::withoutTenantScope()->where('pbx_node_id', $node->id)->where('number', $number)->first();

        $owner = match ($dialNumber?->owner_type) {
            Extension::MORPH => Extension::withoutTenantScope()->find($dialNumber->owner_id),
            RingGroup::MORPH => RingGroup::withoutTenantScope()->find($dialNumber->owner_id),
            default => null,
        };

        if ($owner === null) {
            return new CompiledNumber($number, 'unused', null, []);
        }

        $range = $this->range($node, $owner->tenant_id, $number);

        if (! $owner->isActive() || ! $this->tenantInService($owner->tenant_id, $node)) {
            return $this->disabled($number);
        }

        return $owner instanceof Extension
            ? $this->extension($node, $owner, $range)
            : $this->ringGroup($node, $owner, $range);
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

    private function extension(PbxNode $node, Extension $extension, TenantNumberRange $range): CompiledNumber
    {
        $number = $extension->extension_number;
        $credential = $extension->sipCredential;

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
        ], $this->numbered([
            $this->callerCheck($range),
            ['Dial', $this->dialString($node, [$number], self::RING_SECONDS)],
            ['Hangup', ''],
        ]));
    }

    private function ringGroup(PbxNode $node, RingGroup $group, TenantNumberRange $range): CompiledNumber
    {
        $members = array_values($group->members()
            ->get()
            ->filter(fn (Extension $member): bool => $member->isActive() && $member->pbx_node_id === $node->id)
            ->map(fn (Extension $member): string => $member->extension_number)
            ->all());

        $dials = match (true) {
            $members === [] => [],
            $group->strategy === RingStrategy::RingAll => [['Dial', $this->dialString($node, $members, $group->ring_seconds)]],
            default => array_map(fn (string $member): array => ['Dial', $this->dialString($node, [$member], $group->ring_seconds)], $members),
        };

        return new CompiledNumber($group->number, 'active', null, $this->numbered([
            $this->callerCheck($range),
            ...$dials,
            $this->failover($group),
        ]));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function failover(RingGroup $group): array
    {
        $target = match ($group->failover_type) {
            FailoverType::Extension => Extension::withoutTenantScope()->where('tenant_id', $group->tenant_id)->find($group->failover_id)?->extension_number,
            FailoverType::RingGroup => RingGroup::withoutTenantScope()->where('tenant_id', $group->tenant_id)->find($group->failover_id)?->number,
            FailoverType::Hangup => null,
        };

        return $target === null ? ['Hangup', ''] : ['Goto', "{$target},1"];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function callerCheck(TenantNumberRange $range): array
    {
        return ['GotoIf', sprintf('$["${CALLERID(num):0:%d}" != "%s"]?%s,1', strlen($range->prefix), $range->prefix, self::DENIED_EXTEN)];
    }

    /**
     * @param  list<string>  $numbers
     */
    private function dialString(PbxNode $node, array $numbers, int $seconds): string
    {
        $endpoint = $node->dialplan['opensips_endpoint'] ?? 'opensips';
        $appdata = implode('&', array_map(fn (string $number): string => "PJSIP/{$number}@{$endpoint}", $numbers)).",{$seconds}";

        if (strlen($appdata) > self::MAX_APPDATA) {
            throw ProvisioningRejected::because('dialplan_too_long', 'The Dial() of '.count($numbers).' members does not fit in one dialplan row.');
        }

        return $appdata;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $steps  app and appdata, in order
     * @return list<array{priority: int, app: string, appdata: string}>
     */
    private function numbered(array $steps): array
    {
        return array_map(
            fn (array $step, int $index): array => ['priority' => $index + 1, 'app' => $step[0], 'appdata' => $step[1]],
            $steps,
            array_keys($steps),
        );
    }

    private function disabled(string $number): CompiledNumber
    {
        return new CompiledNumber($number, 'disabled', null, [
            ['priority' => 1, 'app' => 'Goto', 'appdata' => self::DENIED_EXTEN.',1'],
        ]);
    }

    private function range(PbxNode $node, string $tenantId, string $number): TenantNumberRange
    {
        $range = TenantNumberRange::query()->where('tenant_id', $tenantId)->where('pbx_node_id', $node->id)->first();

        if ($range === null || ! $range->contains($number)) {
            throw ProvisioningRejected::because('number_outside_prefix', "Number {$number} is not in its tenant's prefix on this node.");
        }

        return $range;
    }

    private function tenantInService(string $tenantId, PbxNode $node): bool
    {
        $tenant = Tenant::query()->find($tenantId);
        $assignment = TenantPbxAssignment::query()->where('tenant_id', $tenantId)->first();

        return $tenant?->isActive() === true
            && $assignment?->isActive() === true
            && $assignment->pbx_node_id === $node->id;
    }
}
