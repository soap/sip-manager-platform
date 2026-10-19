<?php

namespace App\Modules\Nodes\Exceptions;

use App\Modules\Nodes\Models\SipNode;
use RuntimeException;

/**
 * The node has no such endpoint, or its credentials have not been fetched from SIP Deployer yet.
 */
class NodeNotConnectable extends RuntimeException
{
    public static function missingEndpoint(SipNode $node, string $endpoint): self
    {
        return new self("Node {$node->name} has no {$endpoint} endpoint.");
    }

    public static function missingCredentials(SipNode $node, string $endpoint): self
    {
        return new self("The {$endpoint} credentials of node {$node->name} have not been fetched from SIP Deployer.");
    }
}
