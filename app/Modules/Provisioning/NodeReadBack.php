<?php

namespace App\Modules\Provisioning;

use App\Modules\Infrastructure\Asterisk\RealtimeAdapter;
use App\Modules\Infrastructure\OpenSips\OpenSipsDbAdapter;
use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Provisioning\Compiler\CompiledCalling;
use App\Modules\Provisioning\Compiler\CompiledNumber;

/**
 * What a node holds for a target, in the shape the compilers give, so its checksum can be compared
 * with the desired one: after a write (read-back) and when reconciling.
 */
class NodeReadBack
{
    public function __construct(private RealtimeAdapter $asterisk, private OpenSipsDbAdapter $openSips) {}

    /**
     * The rows the node holds where the compiled number would be written.
     */
    public function number(PbxNode $node, CompiledNumber $compiled): CompiledNumber
    {
        $mailboxKnown = $compiled->voicemailContext !== null && $compiled->username !== null && $node->hasVoicemail();

        return new CompiledNumber(
            $compiled->number,
            $compiled->state,
            $compiled->sipDomain === null || $compiled->username === null ? null : ($this->openSips->subscribers($node, $compiled->username, $compiled->sipDomain)[0] ?? null),
            $this->asterisk->exten($node, $compiled->number),
            $mailboxKnown ? $this->asterisk->mailbox($node, (string) $compiled->voicemailContext, (string) $compiled->username) : null,
            permissions: $this->asterisk->exten($node, $compiled->permissionExten()),
        );
    }

    /**
     * Every exten the node holds under the prefixes the tenant's calling dialplan owns.
     *
     * @return array<string, list<array{priority: int, app: string, appdata: string}>>
     */
    public function calling(PbxNode $node, CompiledCalling $compiled): array
    {
        return $this->asterisk->extensStartingWith($node, $compiled->ownedPrefixes());
    }
}
