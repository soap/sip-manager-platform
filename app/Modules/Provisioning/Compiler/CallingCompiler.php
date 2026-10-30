<?php

namespace App\Modules\Provisioning\Compiler;

use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Telephony\Enums\AuthorizationCodeStatus;
use App\Modules\Telephony\Enums\CallCategory;
use App\Modules\Telephony\Models\AuthorizationCode;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Compiles a tenant's calling dialplan on its node (plan D7): what its phones may dial beyond their
 * own numbers, as it is now. The tenant with code 1042 gets:
 *
 * - _10428XXXX. for 8 + another tenant's code + a number. Every check jumps ahead when it passes
 *   and falls through to 1042denied, so an expression Asterisk cannot evaluate refuses the call:
 *   the number is digits; the destination takes our calls (its marker exten 1042x<dst> exists)
 *   and has the number; the caller's permission exten 1042p<caller> (NumberCompiler) returns
 *   "xa" or "x<dst>", or "XA"/"X<dst>" and the caller enters a code that unlocks other tenants.
 *   The callee then sees 81042<caller>, which it can dial back.
 * - 1042x<dst> for each tenant that takes calls from 1042: NoOp(), only looked for.
 * - _1042<pattern> for each category that leaves the platform: 1042denied until there are trunks
 *   (Phase 4), without asking for a code.
 * - 1042auth, the subroutine that asks for a code (ARG1 = the category's flag), and 1042a<hash>
 *   for each usable authorization code: Return(<id>/<flags>/). The subroutine looks for the exten
 *   of the md5 of the tenant's salt and the digits entered; three wrong tries end at 1042denied. A
 *   right one puts "authcode:<id>" in the CDR's userfield.
 *
 * Realtime rows run in the entry context (from-opensips) through its switch, so the dialplan looks
 * for extens with DIALPLAN_EXISTS(${CONTEXT},…). pbx_realtime takes the first pattern that matches,
 * not the most specific, so no two patterns of a tenant may overlap.
 *
 * - 1042denied, where the tenant's refused calls go, also from its disabled numbers: the call
 *   ends there, so the CDR's dst names the tenant (plan §7).
 *
 * A suspended tenant keeps only 1042denied on its node, for its numbers that now refuse every
 * call; a tenant not served by the node has no calling dialplan there.
 */
class CallingCompiler
{
    /** Tries to enter a code before the call is refused. */
    public const CODE_ATTEMPTS = 3;

    /** Seconds Read() waits for the digits of a code. */
    public const CODE_TIMEOUT = 8;

    public function __construct(private NumberCompiler $numbers) {}

    public function compile(PbxNode $node, string $tenantCode): CompiledCalling
    {
        $tenant = Tenant::query()->where('code', $tenantCode)->first();

        if ($tenant === null) {
            return new CompiledCalling($tenantCode, 'removed', []);
        }

        if (! $this->numbers->inService($tenant, $node)) {
            $assigned = TenantPbxAssignment::query()->where('tenant_id', $tenant->id)->where('pbx_node_id', $node->id)->exists();

            return $assigned
                ? new CompiledCalling($tenantCode, 'suspended', [NumberCompiler::deniedExten($tenantCode) => $this->numbers->denied()])
                : new CompiledCalling($tenantCode, 'removed', []);
        }

        $codes = self::usableCodes($tenant)->orderBy('id')->get();
        $extens = [NumberCompiler::deniedExten($tenant->code) => $this->numbers->denied()];
        $codeExtens = [];

        if ($codes->isNotEmpty()) {
            $extens[self::authExten($tenant)] = $this->numbered($this->askForCode($tenant));
        }

        foreach ($codes as $code) {
            $exten = $tenant->code.'a'.$code->node_hash;
            $extens[$exten] = $this->numbered([['Return', $code->id.'/'.implode('/', array_map(fn (CallCategory $category): string => $category->flag(), $code->categoryCases())).'/']]);
            $codeExtens[$exten] = $code->id;
        }

        foreach (Tenant::acceptingCallsFrom($tenant)->orderBy('code')->pluck('code') as $destination) {
            $extens[$tenant->code.'x'.$destination] = $this->numbered([['NoOp', "Tenant {$destination} takes calls from {$tenant->code}"]]);
        }

        foreach (CallCategory::cases() as $category) {
            $extens['_'.$tenant->code.$category->pattern()] = $this->numbered($category === CallCategory::CrossTenant
                ? $this->crossTenant($tenant, $this->anyCodeUnlocks($codes, $category))
                : [['Goto', NumberCompiler::deniedExten($tenant->code).',1']]);
        }

        return new CompiledCalling($tenant->code, 'active', $extens, $codeExtens, $tenant->auth_salt);
    }

    /**
     * Codes a caller of the tenant can use now: active and not expired.
     *
     * @return Builder<AuthorizationCode>
     */
    public static function usableCodes(Tenant $tenant): Builder
    {
        return AuthorizationCode::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('status', AuthorizationCodeStatus::Active)
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public static function authExten(Tenant $tenant): string
    {
        return $tenant->code.'auth';
    }

    /**
     * @param  Collection<int, AuthorizationCode>  $codes
     */
    private function anyCodeUnlocks(Collection $codes, CallCategory $category): bool
    {
        return $codes->contains(fn (AuthorizationCode $code): bool => $code->unlocks($category));
    }

    /**
     * EXTEN is <code>8<dst><number>: the destination's code at 5, its number from 9.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function crossTenant(Tenant $tenant, bool $withCode): array
    {
        $flag = CallCategory::CrossTenant->flag();
        $upper = strtoupper($flag);
        $destination = '${EXTEN:5:4}';
        $denied = ['Goto', NumberCompiler::deniedExten($tenant->code).',1'];

        $checks = [
            ['GotoIf', '$[${REGEX("^[0-9]+$" ${EXTEN:9})}]?3'],
            $denied,
            ['GotoIf', '$[${DIALPLAN_EXISTS(${CONTEXT},'.$tenant->code.'x'.$destination.',1)} & ${DIALPLAN_EXISTS(${CONTEXT},${EXTEN:5},1)}]?5'],
            $denied,
            ['Gosub', $tenant->code.'p${CALLERID(num)},1'],
        ];

        $permission = $withCode ? [
            ['GotoIf', '$[${REGEX("/('.$flag.'a|'.$flag.$destination.')/" ${GOSUB_RETVAL})}]?10'],
            ['GotoIf', '$[${REGEX("/('.$upper.'A|'.$upper.$destination.')/" ${GOSUB_RETVAL})}]?9'],
            $denied,
            ['Gosub', self::authExten($tenant).',1('.$flag.')'],
        ] : [
            ['GotoIf', '$[${REGEX("/('.$flag.'a|'.$flag.$destination.')/" ${GOSUB_RETVAL})}]?8'],
            $denied,
        ];

        return [
            ...$checks,
            ...$permission,
            ['Set', 'CALLERID(num)=8'.$tenant->code.'${CALLERID(num)}'],
            ['Goto', '${EXTEN:5},1'],
        ];
    }

    /**
     * The subroutine that asks for a code; returns to the caller only when the code unlocks ARG1.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function askForCode(Tenant $tenant): array
    {
        return [
            ['Set', 'TRIES=0'],
            ['Read', 'AUTHCODE,agent-pass,'.AuthorizationCode::MAX_LENGTH.',,1,'.self::CODE_TIMEOUT],
            ['Set', 'AUTHEXTEN='.$tenant->code.'a${MD5('.$tenant->auth_salt.'${AUTHCODE})}'],
            ['GotoIf', '$[${DIALPLAN_EXISTS(${CONTEXT},${AUTHEXTEN},1)}]?6'],
            ['Goto', '8'],
            ['Gosub', '${AUTHEXTEN},1'],
            ['GotoIf', '$[${REGEX("/${ARG1}/" ${GOSUB_RETVAL})}]?13'],
            // wrong code, or one that does not unlock this category
            ['UserEvent', 'AuthCodeFailed,Tenant: '.$tenant->code.',Caller: ${CALLERID(num)}'],
            ['Set', 'TRIES=$[${TRIES} + 1]'],
            ['Playback', 'auth-incorrect'],
            ['GotoIf', '$[${TRIES} < '.self::CODE_ATTEMPTS.']?2'],
            ['Goto', NumberCompiler::deniedExten($tenant->code).',1'],
            ['Set', 'CDR(userfield)=authcode:${CUT(GOSUB_RETVAL,/,1)}'],
            ['Return', ''],
        ];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $steps
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
}
