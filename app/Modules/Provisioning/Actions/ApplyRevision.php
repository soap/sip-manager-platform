<?php

namespace App\Modules\Provisioning\Actions;

use App\Modules\Infrastructure\Asterisk\AmiAdapter;
use App\Modules\Infrastructure\Asterisk\RealtimeAdapter;
use App\Modules\Infrastructure\OpenSips\OpenSipsDbAdapter;
use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Provisioning\Compiler\CompiledNumber;
use App\Modules\Provisioning\Compiler\NumberCompiler;
use App\Modules\Provisioning\Enums\OperationStatus;
use App\Modules\Provisioning\Enums\RevisionStatus;
use App\Modules\Provisioning\ErrorClassifier;
use App\Modules\Provisioning\Exceptions\ProvisioningRejected;
use App\Modules\Provisioning\Exceptions\ReadBackMismatch;
use App\Modules\Provisioning\Models\ConfigRevision;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Steps 4–8 of the pipeline (plan v0.2 §6.1) for one revision: compile the number's desired state
 * as it is now, validate it against the node, write Asterisk first and OpenSIPS last (so a phone
 * cannot register before its dialplan is ready), read both back and compare checksums, then ask
 * Asterisk over AMI whether it sees the dialplan and the mailbox. Ends applied, retrying with backoff,
 * or failed.
 */
class ApplyRevision
{
    /** Seconds to wait before each retry; after the last one the operation fails. */
    public const BACKOFF = [10, 30, 120, 600, 1800];

    public function __construct(
        private NumberCompiler $compiler,
        private RealtimeAdapter $asterisk,
        private OpenSipsDbAdapter $openSips,
        private AmiAdapter $ami,
        private ErrorClassifier $classifier,
    ) {}

    /**
     * @return int|null seconds after which to try again, or null when done
     */
    public function handle(int $revisionId): ?int
    {
        $revision = ConfigRevision::query()->with('node')->find($revisionId);

        if ($revision === null || in_array($revision->status, [RevisionStatus::Applied, RevisionStatus::Failed, RevisionStatus::Superseded], true)) {
            return null;
        }

        if ($this->hasNewerRevision($revision)) {
            $this->finish($revision, RevisionStatus::Superseded, OperationStatus::Superseded);

            return null;
        }

        $attempt = $this->start($revision);

        try {
            [$compiled, $asterisk] = $this->apply($revision);
        } catch (Throwable $exception) {
            return $this->failed($revision, $attempt, $exception);
        }

        $revision->update([
            'status' => RevisionStatus::Applied,
            'checksum' => $compiled->checksum(),
            'compiled' => $compiled->withoutSecrets(),
            'apply_result' => ['read_back' => 'match', 'asterisk' => $asterisk, 'state' => $compiled->state, 'attempt' => $attempt, 'applied_at' => now()->toIso8601String()],
        ]);
        $revision->operations()->update(['status' => OperationStatus::Applied, 'error_code' => null, 'error_message' => null, 'next_attempt_at' => null]);

        return null;
    }

    /**
     * @return array{CompiledNumber, string} the compiled rows, and whether Asterisk confirmed them
     */
    private function apply(ConfigRevision $revision): array
    {
        $node = $revision->node;
        $number = $revision->targetNumber();

        if ($number === null) {
            throw ProvisioningRejected::because('unknown_target', "Revision {$revision->id} has an unknown target {$revision->target}.");
        }

        $this->validateNode($node);

        $compiled = $this->compiler->compile($node, $number);
        $revision->update(['status' => RevisionStatus::Validated, 'validation_report' => ['ok' => true, 'state' => $compiled->state]]);

        // shared rows every tenant relies on, written each time so a reinstalled node gets them back
        $this->asterisk->replaceExten($node, NumberCompiler::DENIED_EXTEN, $this->compiler->denied());

        if ($node->hasVoicemail()) {
            $this->asterisk->replaceExten($node, NumberCompiler::VOICEMAIL_MAIN_EXTEN, $this->compiler->voicemailMain());
            $this->asterisk->replaceMailbox($node, $number, $compiled->voicemail);
        }

        $this->asterisk->replaceExten($node, $number, $compiled->dialplan);
        $this->openSips->replaceSubscriber($node, $number, $compiled->subscriber);

        $readBack = new CompiledNumber(
            $number,
            $compiled->state,
            $this->openSips->subscribers($node, $number)[0] ?? null,
            $this->asterisk->exten($node, $number),
            $node->hasVoicemail() ? $this->asterisk->mailbox($node, $number) : null,
        );

        if ($readBack->checksum() !== $compiled->checksum()) {
            throw new ReadBackMismatch("The rows of {$number} read back from {$node->name} differ from those written.");
        }

        return [$compiled, $this->confirmWithAsterisk($node, $compiled)];
    }

    /**
     * Rows in the database are not yet a working number: Asterisk must find every priority through
     * the realtime switch, and app_voicemail the mailbox (plan v0.2 §6.3). A node without AMI is
     * trusted on the database read-back alone.
     *
     * @return string "confirmed", or "unchecked" when the node has no AMI
     */
    private function confirmWithAsterisk(PbxNode $node, CompiledNumber $compiled): string
    {
        if (! isset($node->endpoints['ami'])) {
            return 'unchecked';
        }

        $missing = $this->ami->missingPriorities($node, $compiled->number, count($compiled->dialplan));

        if ($missing !== []) {
            throw new ReadBackMismatch(sprintf(
                'Asterisk on %s does not see priorities %s of %s in %s.',
                $node->name,
                implode(', ', $missing),
                $compiled->number,
                $this->ami->entryContext($node),
            ));
        }

        $box = $compiled->voicemail;

        if ($box !== null && ! $this->ami->hasMailbox($node, $box['mailbox'], $box['context'])) {
            throw new ReadBackMismatch("Asterisk on {$node->name} does not find mailbox {$box['mailbox']}@{$box['context']}.");
        }

        return 'confirmed';
    }

    private function validateNode(PbxNode $node): void
    {
        if ($node->removed_at !== null) {
            throw ProvisioningRejected::because('node_removed', "Node {$node->name} has been removed from SIP Deployer.");
        }

        if (! $node->hasOpenSips() || ! in_array('realtime', $node->asteriskFeatures(), true)) {
            throw ProvisioningRejected::because('node_capability', "Node {$node->name} needs OpenSIPS and Asterisk with realtime.");
        }
    }

    /**
     * A newer revision of the same number that has not failed writes the same desired state, so
     * this one can stand down.
     */
    private function hasNewerRevision(ConfigRevision $revision): bool
    {
        return ConfigRevision::query()
            ->where('pbx_node_id', $revision->pbx_node_id)
            ->where('target', $revision->target)
            ->where('id', '>', $revision->id)
            ->where('status', '!=', RevisionStatus::Failed)
            ->exists();
    }

    /**
     * @return int the attempt number
     */
    private function start(ConfigRevision $revision): int
    {
        $revision->update(['status' => RevisionStatus::Applying]);
        $revision->operations()->update(['status' => OperationStatus::Running]);
        $revision->operations()->increment('attempts');

        return (int) $revision->operations()->max('attempts');
    }

    private function failed(ConfigRevision $revision, int $attempt, Throwable $exception): ?int
    {
        $error = $this->classifier->classify($exception);
        $delay = $error['retryable'] ? (self::BACKOFF[$attempt - 1] ?? null) : null;

        Log::warning('Provisioning attempt failed.', [
            'config_revision_id' => $revision->id,
            'pbx_node_id' => $revision->pbx_node_id,
            'target' => $revision->target,
            'attempt' => $attempt,
            'error_code' => $error['code'],
            'retry_in' => $delay,
        ]);

        $revision->operations()->update([
            'status' => $delay === null ? OperationStatus::Failed : OperationStatus::Retrying,
            'error_code' => $error['code'],
            'error_message' => $error['message'],
            'next_attempt_at' => $delay === null ? null : now()->addSeconds($delay),
        ]);
        $revision->update([
            'status' => $delay === null ? RevisionStatus::Failed : RevisionStatus::Draft,
            'apply_result' => ['error_code' => $error['code'], 'attempt' => $attempt],
        ]);

        return $delay;
    }

    private function finish(ConfigRevision $revision, RevisionStatus $revisionStatus, OperationStatus $operationStatus): void
    {
        $revision->update(['status' => $revisionStatus]);
        $revision->operations()->update(['status' => $operationStatus, 'next_attempt_at' => null]);
    }
}
