<?php

namespace App\Modules\Nodes\Exceptions;

use App\Modules\Nodes\Models\PbxNode;
use RuntimeException;

/**
 * The node has no such endpoint, or its credentials have not been fetched from SIP Deployer yet.
 */
class NodeNotConnectable extends RuntimeException
{
    public static function missingEndpoint(PbxNode $node, string $endpoint): self
    {
        return new self("Node {$node->name} has no {$endpoint} endpoint.");
    }

    public static function missingCredentials(PbxNode $node, string $endpoint): self
    {
        return new self("The {$endpoint} credentials of node {$node->name} have not been fetched from SIP Deployer.");
    }
}
