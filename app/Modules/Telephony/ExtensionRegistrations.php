<?php

namespace App\Modules\Telephony;

use App\Modules\Infrastructure\OpenSips\OpenSipsDbAdapter;
use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Telephony\Models\Extension;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Whether the phones of extensions are registered, read live from the OpenSIPS location table of
 * their node (one query per node). A node that cannot be asked gives "unknown" instead of failing
 * the page.
 */
class ExtensionRegistrations
{
    public function __construct(private OpenSipsDbAdapter $openSips) {}

    /**
     * @param  Collection<int, Extension>  $extensions
     * @return array<int, array{status: 'registered'|'not_registered'|'unknown', contacts: list<array{address: string, user_agent: string, registered_at: string|null, expires_at: string|null}>}>
     */
    public function of(Collection $extensions): array
    {
        $states = [];

        foreach ($extensions->groupBy('pbx_node_id') as $nodeId => $onNode) {
            $node = PbxNode::query()->find($nodeId);

            try {
                $registrations = $node === null ? null : $this->openSips->registrations($node, array_values($onNode->map(fn (Extension $extension): string => $extension->extension_number)->all()));
            } catch (Throwable $exception) {
                Log::warning('Could not read the registrations of a node.', ['pbx_node_id' => $nodeId, 'error' => $exception->getMessage()]);
                $registrations = null;
            }

            foreach ($onNode as $extension) {
                $states[$extension->id] = $this->state($registrations === null ? null : ($registrations[$extension->extension_number] ?? []));
            }
        }

        return $states;
    }

    /**
     * @param  list<array{address: string, user_agent: string, registered_at: CarbonImmutable|null, expires_at: CarbonImmutable|null}>|null  $contacts
     * @return array{status: 'registered'|'not_registered'|'unknown', contacts: list<array{address: string, user_agent: string, registered_at: string|null, expires_at: string|null}>}
     */
    private function state(?array $contacts): array
    {
        if ($contacts === null) {
            return ['status' => 'unknown', 'contacts' => []];
        }

        return [
            'status' => $contacts === [] ? 'not_registered' : 'registered',
            'contacts' => array_map(fn (array $contact): array => [
                'address' => $contact['address'],
                'user_agent' => $contact['user_agent'],
                'registered_at' => $contact['registered_at']?->toIso8601String(),
                'expires_at' => $contact['expires_at']?->toIso8601String(),
            ], $contacts),
        ];
    }
}
