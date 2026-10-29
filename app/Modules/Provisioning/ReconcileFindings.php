<?php

namespace App\Modules\Provisioning;

/**
 * What one reconcile of a node found: each drifted row, and the targets to write again for them.
 */
final class ReconcileFindings
{
    /** @var list<array{kind: 'missing'|'changed'|'unexpected', target: string|null, item: string}> */
    public array $drift = [];

    /** @var array<string, array{kind: 'number'|'domain'|'calling', tenant_id: string, aggregate_type: string, aggregate_id: int, number?: string}> keyed by target */
    public array $fixes = [];

    /**
     * @param  'missing'|'changed'|'unexpected'  $kind
     */
    public function record(string $kind, ?string $target, string $item): void
    {
        $this->drift[] = ['kind' => $kind, 'target' => $target, 'item' => $item];
    }

    /**
     * @param  array{kind: 'number'|'domain'|'calling', tenant_id: string, aggregate_type: string, aggregate_id: int, number?: string}  $fix
     */
    public function fix(string $target, array $fix): void
    {
        $this->fixes[$target] ??= $fix;
    }

    /**
     * @param  'missing'|'changed'|'unexpected'  $kind
     */
    public function count(string $kind): int
    {
        return count(array_filter($this->drift, fn (array $entry): bool => $entry['kind'] === $kind));
    }
}
