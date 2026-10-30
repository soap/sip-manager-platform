<?php

namespace App\Modules\Provisioning\Compiler;

use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Provisioning\Exceptions\ProvisioningRejected;
use App\Modules\Telephony\Enums\FailoverType;
use App\Modules\Telephony\Enums\RingStrategy;
use App\Modules\Telephony\Models\DialNumber;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Telephony\Models\RingGroup;
use App\Modules\Tenancy\Models\Tenant;

/**
 * Compiles the desired state of one number on a node, as it is now (plan v0.2 §6.2, D6), so applying
 * revisions out of order still ends with the latest state.
 *
 * Every tenant has its own SIP domain, and OpenSIPS hands each call of a phone to Asterisk with the
 * caller's tenant code before the dialed number. The extens of a tenant in "managed" therefore all
 * start with its code, and a phone can only reach extens of its own tenant.
 *
 * An active extension 101 of the tenant with code 1042 and domain acme.sip.example.com becomes:
 * - subscriber 101@acme.sip.example.com with its ha1, so its phone can register and call;
 * - exten 1042101: 1 Dial(PJSIP/1042101@opensips,30), 2 Hangup(). OpenSIPS turns 1042101 back
 *   into 101@acme.sip.example.com.
 *
 * An active ring group 500 (D5) becomes exten 1042500: one Dial of all active members (ring_all)
 * or one Dial per active member (sequential), then its failover: Goto another number of the
 * tenant, or Hangup(). Dial only returns when nobody answered, so the next row runs only for an
 * unanswered call.
 *
 * With voicemail on, an extension also gets the box 101@t1042 with its PIN, and an unanswered or
 * busy call is left there (VoiceMail ... b or u) before Hangup(). Phones reach their own box with
 * *97, which reaches Asterisk as 1042*97.
 *
 * An active extension also gets the permission exten 1042p101 (plan D7): Return() with the flags
 * of its calling profile, which CallingCompiler's dialplan asks for with Gosub. Without that row
 * the Gosub fails and the call ends, so a disabled extension calls nowhere outside the tenant.
 *
 * Anything disabled, or of a suspended tenant or assignment, loses its box and sends every caller
 * to denied. A disabled extension keeps its subscriber with ha1 "disabled", which OpenSIPS refuses
 * with a plain 403 instead of a failed authentication, so the phone's retries cannot get the
 * office address banned by fail2ban (SIP Deployer contract). A number nobody owns gets no rows.
 */
class NumberCompiler
{
    /** Seconds a phone rings before the call gives up. */
    public const RING_SECONDS = 30;

    /**
     * Shared by every tenant: where refused calls went before each tenant got its own
     * (deniedExten()); kept for rows that may still go there.
     */
    public const DENIED_EXTEN = 'denied';

    /** The feature code for one's own mailbox, as the phone dials it. */
    public const VOICEMAIL_MAIN_EXTEN = '*97';

    /** The ha1 of a disabled account; a real digest is hex, so it never matches a password. */
    public const DISABLED_HA1 = 'disabled';

    /** The width of extensions.appdata in the Asterisk realtime schema. */
    public const MAX_APPDATA = 256;

    /**
     * @param  string  $exten  the number as Asterisk knows it: tenant code, then the tenant's number
     *
     * @throws ProvisioningRejected when the number cannot be written to this node
     */
    public function compile(PbxNode $node, string $exten): CompiledNumber
    {
        $tenant = Tenant::query()->where('code', substr($exten, 0, Tenant::CODE_DIGITS))->first();

        if ($tenant === null) {
            return new CompiledNumber($exten, 'unused', null, []);
        }

        $number = substr($exten, Tenant::CODE_DIGITS);
        $dialNumber = DialNumber::withoutTenantScope()->where('tenant_id', $tenant->id)->where('number', $number)->first();

        $owner = match ($dialNumber?->owner_type) {
            Extension::MORPH => Extension::withoutTenantScope()->find($dialNumber->owner_id),
            RingGroup::MORPH => RingGroup::withoutTenantScope()->find($dialNumber->owner_id),
            default => null,
        };

        if ($owner === null) {
            return $this->unused($tenant, $exten, $number);
        }

        if ($owner->pbx_node_id !== $node->id) {
            throw ProvisioningRejected::because('wrong_node', "Number {$number} of {$tenant->name} lives on another node.");
        }

        if (! $owner->isActive() || ! $this->inService($tenant, $node)) {
            return $this->disabled($tenant, $exten, $number, $owner instanceof Extension ? $owner : null);
        }

        return $owner instanceof Extension
            ? $this->extension($node, $tenant, $owner)
            : $this->ringGroup($node, $tenant, $owner);
    }

    /**
     * The tenant's row in the OpenSIPS domain table while it is assigned to the node, or null.
     *
     * @return array{domain: string, attrs: string}|null
     */
    public function domain(PbxNode $node, string $tenantCode): ?array
    {
        $tenant = Tenant::query()->where('code', $tenantCode)->first();

        $assigned = $tenant !== null && TenantPbxAssignment::query()
            ->where('tenant_id', $tenant->id)
            ->where('pbx_node_id', $node->id)
            ->exists();

        return $assigned ? ['domain' => $tenant->sip_domain, 'attrs' => $tenant->code] : null;
    }

    /**
     * The tenant's own exten for refused calls (CallingCompiler writes it), e.g. 1042denied: the
     * call ends there, so the CDR's dst starts with the tenant code.
     */
    public static function deniedExten(string $tenantCode): string
    {
        return $tenantCode.self::DENIED_EXTEN;
    }

    /**
     * Where refused calls go: "not in service", then hang up with cause 21 (rejected). The rows of
     * the shared exten and of each tenant's own one.
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

    /**
     * The tenant's feature code for one's own mailbox: the caller is one of the tenant's numbers, so
     * the mailbox is the caller's number in the tenant's context, and the PIN guards each box.
     *
     * @return list<array{priority: int, app: string, appdata: string}>
     */
    public function voicemailMain(Tenant $tenant): array
    {
        return [
            ['priority' => 1, 'app' => 'VoiceMailMain', 'appdata' => '${CALLERID(num)}@'.self::voicemailContext($tenant)],
            ['priority' => 2, 'app' => 'Hangup', 'appdata' => ''],
        ];
    }

    /**
     * The voicemail context of the tenant's boxes: "t" and its code.
     */
    public static function voicemailContext(Tenant $tenant): string
    {
        return 't'.$tenant->code;
    }

    private function extension(PbxNode $node, Tenant $tenant, Extension $extension): CompiledNumber
    {
        $number = $extension->extension_number;
        $credential = $extension->sipCredential;

        if ($credential === null) {
            throw ProvisioningRejected::because('missing_credential', "Extension {$number} has no SIP credential.");
        }

        $voicemail = $extension->hasVoicemail();

        if ($voicemail && ! $node->hasVoicemail()) {
            throw ProvisioningRejected::because('node_capability', "Node {$node->name} has no voicemail; turn voicemail off for {$number}.");
        }

        return new CompiledNumber($tenant->routingNumber($number), 'active', [
            'username' => $credential->sip_username,
            'domain' => $tenant->sip_domain,
            'ha1' => $credential->ha1($tenant->sip_domain),
            'ha1_sha256' => $credential->ha1Sha256($tenant->sip_domain),
        ], $this->numbered([
            ['Dial', $this->dialString($node, [$tenant->routingNumber($number)], self::RING_SECONDS)],
            // a busy phone gets the busy greeting, anything else the unavailable one
            ...($voicemail ? [['VoiceMail', $this->mailboxOf($tenant, $extension).',${IF($["${DIALSTATUS}" = "BUSY"]?b:u)}']] : []),
            ['Hangup', ''],
        ]), $voicemail ? [
            'context' => self::voicemailContext($tenant),
            'mailbox' => $number,
            'password' => (string) $extension->voicemail_pin,
            'fullname' => $extension->display_name,
        ] : null, $credential->sip_username, $tenant->sip_domain, self::voicemailContext($tenant), [
            ['priority' => 1, 'app' => 'Return', 'appdata' => $this->permissionFlags($extension)],
        ]);
    }

    /**
     * The flags of the extension's calling profile (plan D7), e.g. "/l/M/xa/"; "/" without a profile.
     *
     * @throws ProvisioningRejected when too many selected tenants do not fit in one row
     */
    private function permissionFlags(Extension $extension): string
    {
        $flags = $extension->effectiveCallingProfile()?->flags() ?? '/';

        if (strlen($flags) > self::MAX_APPDATA) {
            throw ProvisioningRejected::because('dialplan_too_long', "The calling profile of {$extension->extension_number} lists too many tenants for one dialplan row.");
        }

        return $flags;
    }

    private function mailboxOf(Tenant $tenant, Extension $extension): string
    {
        return $extension->extension_number.'@'.self::voicemailContext($tenant);
    }

    private function ringGroup(PbxNode $node, Tenant $tenant, RingGroup $group): CompiledNumber
    {
        $members = array_values($group->members()
            ->get()
            ->filter(fn (Extension $member): bool => $member->isActive() && $member->pbx_node_id === $node->id)
            ->map(fn (Extension $member): string => $tenant->routingNumber($member->extension_number))
            ->all());

        $dials = match (true) {
            $members === [] => [],
            $group->strategy === RingStrategy::RingAll => [['Dial', $this->dialString($node, $members, $group->ring_seconds)]],
            default => array_map(fn (string $member): array => ['Dial', $this->dialString($node, [$member], $group->ring_seconds)], $members),
        };

        return new CompiledNumber($tenant->routingNumber($group->number), 'active', null, $this->numbered([
            ...$dials,
            ...$this->failover($node, $tenant, $group),
        ]), null, $group->number, $tenant->sip_domain, self::voicemailContext($tenant));
    }

    /**
     * Goto another number of the tenant, leave a message in an extension's mailbox, or hang up
     * when the target is gone or has no voicemail.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function failover(PbxNode $node, Tenant $tenant, RingGroup $group): array
    {
        $extension = in_array($group->failover_type, [FailoverType::Extension, FailoverType::Voicemail], true)
            ? Extension::withoutTenantScope()->where('tenant_id', $group->tenant_id)->find($group->failover_id)
            : null;

        $steps = match ($group->failover_type) {
            FailoverType::Extension => $extension === null ? [] : [['Goto', $tenant->routingNumber($extension->extension_number).',1']],
            FailoverType::RingGroup => ($target = RingGroup::withoutTenantScope()->where('tenant_id', $group->tenant_id)->find($group->failover_id)) === null
                ? []
                : [['Goto', $tenant->routingNumber($target->number).',1']],
            FailoverType::Voicemail => $extension?->isActive() === true && $extension->hasVoicemail() && $node->hasVoicemail()
                ? [['VoiceMail', $this->mailboxOf($tenant, $extension).',u']]
                : [],
            FailoverType::Hangup => [],
        };

        return [...$steps, ...(($steps[0][0] ?? null) === 'Goto' ? [] : [['Hangup', '']])];
    }

    /**
     * @param  list<string>  $extens  numbers as Asterisk knows them, which OpenSIPS maps to the phones
     */
    private function dialString(PbxNode $node, array $extens, int $seconds): string
    {
        $endpoint = $node->dialplan['opensips_endpoint'] ?? 'opensips';
        $appdata = implode('&', array_map(fn (string $exten): string => "PJSIP/{$exten}@{$endpoint}", $extens)).",{$seconds}";

        if (strlen($appdata) > self::MAX_APPDATA) {
            throw ProvisioningRejected::because('dialplan_too_long', 'The Dial() of '.count($extens).' members does not fit in one dialplan row.');
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

    /**
     * A number of the tenant that nothing owns: no rows, and no subscriber or box left behind.
     */
    private function unused(Tenant $tenant, string $exten, string $number): CompiledNumber
    {
        return new CompiledNumber($exten, 'unused', null, [], null, $number, $tenant->sip_domain, self::voicemailContext($tenant));
    }

    /**
     * Callers go to denied; an extension's phone is refused by OpenSIPS as a disabled account.
     */
    private function disabled(Tenant $tenant, string $exten, string $number, ?Extension $extension): CompiledNumber
    {
        $username = $extension?->sipCredential->sip_username ?? $number;

        return new CompiledNumber($exten, 'disabled', $extension === null ? null : [
            'username' => $username,
            'domain' => $tenant->sip_domain,
            'ha1' => self::DISABLED_HA1,
            'ha1_sha256' => self::DISABLED_HA1,
        ], [
            ['priority' => 1, 'app' => 'Goto', 'appdata' => self::deniedExten($tenant->code).',1'],
        ], null, $username, $tenant->sip_domain, self::voicemailContext($tenant));
    }

    /**
     * Whether the node serves the tenant now: tenant and assignment active, on this node.
     */
    public function inService(Tenant $tenant, PbxNode $node): bool
    {
        $assignment = TenantPbxAssignment::query()->where('tenant_id', $tenant->id)->first();

        return $tenant->isActive()
            && $assignment?->isActive() === true
            && $assignment->pbx_node_id === $node->id;
    }
}
