<?php

namespace App\Modules\Infrastructure\Asterisk;

use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Nodes\NodeConnections;
use Closure;

/**
 * What this application asks a node's Asterisk over AMI: one login per question, logged off
 * afterwards. Read-back goes through the dialplan functions, because the managed context is only
 * reached through the realtime switch of the entry context, which "dialplan show" does not list
 * (plan v0.2 §6.3).
 */
class AmiAdapter
{
    public const ENTRY_CONTEXT = 'from-opensips';

    public function __construct(private NodeConnections $connections) {}

    /**
     * Logs in and reports the Asterisk version, to prove the address and credentials work.
     */
    public function version(PbxNode $node): string
    {
        return $this->session($node, fn (AmiClient $ami): string => 'Asterisk '.($ami->action('CoreSettings')->get('AsteriskVersion') ?? 'answered'));
    }

    /**
     * Priorities 1 to $count of the exten that Asterisk does not find when a call from OpenSIPS
     * dials it: empty when it sees every one of them.
     *
     * @return list<int>
     */
    public function missingPriorities(PbxNode $node, string $exten, int $count): array
    {
        return $this->missingPrioritiesOf($node, [$exten => $count])[$exten] ?? [];
    }

    /**
     * missingPriorities() for several extens in one login: only the extens with missing priorities.
     *
     * @param  array<string, int>  $counts  exten (or a number a pattern matches) => its number of priorities
     * @return array<string, list<int>>
     */
    public function missingPrioritiesOf(PbxNode $node, array $counts): array
    {
        $counts = array_filter($counts, fn (int $count): bool => $count > 0);

        if ($counts === []) {
            return [];
        }

        $context = $this->entryContext($node);

        return $this->session($node, function (AmiClient $ami) use ($counts, $context): array {
            $missing = [];

            foreach ($counts as $exten => $count) {
                $priorities = array_values(array_filter(
                    range(1, $count),
                    fn (int $priority): bool => $ami->getvar("DIALPLAN_EXISTS({$context},{$exten},{$priority})") !== '1',
                ));

                if ($priorities !== []) {
                    $missing[(string) $exten] = $priorities;
                }
            }

            return $missing;
        });
    }

    /**
     * Whether app_voicemail finds the mailbox, which it looks up in the realtime table.
     */
    public function hasMailbox(PbxNode $node, string $mailbox, string $context): bool
    {
        return $this->session($node, fn (AmiClient $ami): bool => $ami->getvar("VM_INFO({$mailbox}@{$context},exists)") === '1');
    }

    /**
     * The context calls from OpenSIPS enter (dialplan.entry_context of the manifest).
     */
    public function entryContext(PbxNode $node): string
    {
        return $node->dialplan['entry_context'] ?? self::ENTRY_CONTEXT;
    }

    /**
     * @template TResult
     *
     * @param  Closure(AmiClient): TResult  $callback
     * @return TResult
     */
    protected function session(PbxNode $node, Closure $callback): mixed
    {
        $ami = $this->connect($node);

        try {
            return $callback($ami);
        } finally {
            $ami->logoff();
        }
    }

    protected function connect(PbxNode $node): AmiClient
    {
        return AmiClient::connect($this->connections->ami($node));
    }
}
