<?php

namespace App\Modules\CallRecords;

/**
 * Who called whom, from the src and dst of a CDR row. OpenSIPS puts the caller's tenant code in
 * front of every dialed number (plan D6), so dst is <code><number> for a call within the tenant
 * and <code>8<other code><number> for a call to another tenant that has not reached it yet. Once a
 * cross-tenant call is let through, the dialplan sets the caller ID to 8<code><number> and moves
 * the call to <other code><number>. Calls the dialplan refuses end in <code>denied: the caller's
 * tenant when its calling dialplan refused, or the called tenant when the number is disabled (then
 * a caller from another tenant is 8<code><number> in src). Before each tenant had its own, they
 * ended in the shared "denied", which names no tenant.
 */
final class CallParties
{
    /**
     * @param  array<string, string>  $tenantIdsByCode
     * @return array{caller_tenant_id: string|null, from_number: string|null, callee_tenant_id: string|null, to_number: string|null}
     */
    public static function from(?string $src, ?string $dst, array $tenantIdsByCode): array
    {
        $callerCode = null;
        $fromNumber = $src;

        if ($src !== null && preg_match('/^8(\d{4})(\d+)$/', $src, $match) === 1 && isset($tenantIdsByCode[$match[1]])) {
            [, $callerCode, $fromNumber] = $match;
        }

        $calleeCode = null;
        $toNumber = null;

        if ($dst !== null && preg_match('/^(\d{4})(.+)$/', $dst, $match) === 1 && isset($tenantIdsByCode[$match[1]])) {
            [, $code, $dialed] = $match;

            if ($dialed === 'denied') {
                // the dialed number is gone; the code is the other tenant's only when src names the caller's
                $calleeCode = $callerCode !== null && $callerCode !== $code ? $code : null;
                $callerCode ??= $code;
            } elseif (preg_match('/^8(\d{4})(.+)$/', $dialed, $other) === 1) {
                $callerCode ??= $code;
                $calleeCode = isset($tenantIdsByCode[$other[1]]) ? $other[1] : null;
                // as dialed when no tenant has the code
                $toNumber = $calleeCode === null ? $dialed : $other[2];
            } else {
                $callerCode ??= $code;
                $calleeCode = $code;
                $toNumber = $dialed;
            }
        }

        return [
            'caller_tenant_id' => $callerCode === null ? null : ($tenantIdsByCode[$callerCode] ?? null),
            'from_number' => $fromNumber,
            'callee_tenant_id' => $calleeCode === null ? null : ($tenantIdsByCode[$calleeCode] ?? null),
            'to_number' => $toNumber,
        ];
    }

    /**
     * The id of the authorization code in the userfield the dialplan sets ("authcode:<id>").
     */
    public static function authorizationCodeId(?string $userfield): ?int
    {
        return $userfield !== null && preg_match('/^authcode:(\d+)$/', $userfield, $match) === 1 ? (int) $match[1] : null;
    }
}
