<?php

namespace App\Modules\CallRecords;

use App\Modules\CallRecords\Models\CallRecord;
use App\Modules\Tenancy\Models\Tenant;

/**
 * The calls a tenant placed or received, newest first, as the tenant sees them: a call with
 * another tenant shows that tenant's end as 8<code><number>, the way it is dialed, and an
 * authorization code only on the caller's side.
 */
class CallHistory
{
    public const PAGE_SIZE = 100;

    /**
     * A page of calls, and the cursor of the next (older) page when there is one.
     *
     * @return array{calls: list<array{id: int, direction: 'internal'|'outgoing'|'incoming', from: string|null, to: string|null, status: string, started_at: string|null, answered_at: string|null, ended_at: string, duration: int, billsec: int, authorization_code: string|null}>, next_cursor: string|null}
     */
    public function page(Tenant $tenant, ?string $cursor = null): array
    {
        $page = CallRecord::query()
            ->forTenant($tenant->id)
            ->with(['callerTenant:id,code', 'calleeTenant:id,code', 'authorizationCode:id,tenant_id,label'])
            ->orderByDesc('ended_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::PAGE_SIZE, cursor: $cursor);

        return [
            'calls' => array_values(array_map(fn (CallRecord $record): array => $this->present($record, $tenant), $page->items())),
            'next_cursor' => $page->nextCursor()?->encode(),
        ];
    }

    /**
     * @return array{id: int, direction: 'internal'|'outgoing'|'incoming', from: string|null, to: string|null, status: string, started_at: string|null, answered_at: string|null, ended_at: string, duration: int, billsec: int, authorization_code: string|null}
     */
    private function present(CallRecord $record, Tenant $tenant): array
    {
        $placed = $record->caller_tenant_id === $tenant->id;
        $direction = match (true) {
            $placed && $record->callee_tenant_id === $tenant->id => 'internal',
            $placed => 'outgoing',
            default => 'incoming',
        };

        return [
            'id' => $record->id,
            'direction' => $direction,
            'from' => $direction === 'incoming' ? $this->dialed($record->callerTenant?->code, $record->from_number) : $record->from_number,
            'to' => $direction === 'outgoing' ? $this->dialed($record->calleeTenant?->code, $record->to_number) : $record->to_number,
            'status' => $record->status->value,
            'started_at' => $record->started_at?->toIso8601String(),
            'answered_at' => $record->answered_at?->toIso8601String(),
            'ended_at' => $record->ended_at->toIso8601String(),
            'duration' => $record->duration,
            'billsec' => $record->billsec,
            'authorization_code' => $placed ? $record->authorizationCode?->label : null,
        ];
    }

    /**
     * Another tenant's number as this tenant dials it.
     */
    private function dialed(?string $code, ?string $number): ?string
    {
        return $code === null || $number === null ? $number : '8'.$code.$number;
    }
}
