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
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Steps 4–8 of the pipeline (plan v0.2 §6.1) for one revision: compile the target's desired state
 * as it is now, validate it against the node, write Asterisk first and OpenSIPS last (so a phone
 * cannot register before its dialplan is ready), read both back and compare checksums, then ask
 * Asterisk over AMI whether it sees the dialplan and the mailbox. Ends applied, retrying with backoff,
 * or failed.
 *
 * A target is a number (exten, tenant code first) or a tenant's SIP domain (its row in the OpenSIPS
 * domain table, which tells OpenSIPS the tenant code of the domain's phones).
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
            $result = $this->apply($revision);
        } catch (Throwable $exception) {
            return $this->failed($revision, $attempt, $exception);
        }

        $revision->update([
            'status' => RevisionStatus::Applied,
            'checksum' => $result['checksum'],
            'compiled' => $result['compiled'],
            'apply_result' => ['read_back' => 'match', 'asterisk' => $result['asterisk'], 'state' => $result['state'], 'attempt' => $attempt, 'applied_at' => now()->toIso8601String()],
        ]);
        $revision->operations()->update(['status' => OperationStatus::Applied, 'error_code' => null, 'error_message' => null, 'next_attempt_at' => null]);

        return null;
    }

    /**
     * @return array{checksum: string, compiled: array<string, mixed>, state: string, asterisk: string}
     */
    private function apply(ConfigRevision $revision): array
    {
        $node = $revision->node;
        $number = $revision->targetNumber();
        $domainCode = $revision->targetDomainCode();

        if ($number === null && $domainCode === null) {
            throw ProvisioningRejected::because('unknown_target', "Revision {$revision->id} has an unknown target {$revision->target}.");
        }

        $this->validateNode($node);

        if ($domainCode !== null) {
            return $this->applyDomain($revision, $domainCode);
        }

        $compiled = $this->compiler->compile($node, (string) $number);
        $revision->update(['status' => RevisionStatus::Validated, 'validation_report' => ['ok' => true, 'state' => $compiled->state]]);

        // shared rows every tenant relies on, written each time so a reinstalled node gets them back
        $this->asterisk->replaceExten($node, NumberCompiler::DENIED_EXTEN, $this->compiler->denied());

        $tenant = Tenant::query()->where('code', substr((string) $number, 0, Tenant::CODE_DIGITS))->first();

        if ($tenant !== null) {
            $this->ensureDomain($node, $tenant);

            if ($node->hasVoicemail()) {
                $this->asterisk->replaceExten($node, $tenant->routingNumber(NumberCompiler::VOICEMAIL_MAIN_EXTEN), $this->compiler->voicemailMain($tenant));
            }
        }

        $this->asterisk->replaceExten($node, $compiled->number, $compiled->dialplan);

        $mailboxKnown = $compiled->voicemailContext !== null && $compiled->username !== null && $node->hasVoicemail();

        if ($mailboxKnown) {
            $this->asterisk->replaceMailbox($node, (string) $compiled->voicemailContext, (string) $compiled->username, $compiled->voicemail);
        }

        if ($compiled->sipDomain !== null && $compiled->username !== null) {
            $this->openSips->replaceSubscriber($node, $compiled->username, $compiled->sipDomain, $compiled->subscriber);
        }

        $readBack = new CompiledNumber(
            $compiled->number,
            $compiled->state,
            $compiled->sipDomain === null || $compiled->username === null ? null : ($this->openSips->subscribers($node, $compiled->username, $compiled->sipDomain)[0] ?? null),
            $this->asterisk->exten($node, $compiled->number),
            $mailboxKnown ? $this->asterisk->mailbox($node, (string) $compiled->voicemailContext, (string) $compiled->username) : null,
        );

        if ($readBack->checksum() !== $compiled->checksum()) {
            throw new ReadBackMismatch("The rows of {$compiled->number} read back from {$node->name} differ from those written.");
        }

        return [
            'checksum' => $compiled->checksum(),
            'compiled' => $compiled->withoutSecrets(),
            'state' => $compiled->state,
            'asterisk' => $this->confirmWithAsterisk($node, $compiled),
        ];
    }

    /**
     * Writes the tenant's domain row, or removes it once the tenant is off the node.
     *
     * @return array{checksum: string, compiled: array<string, mixed>, state: string, asterisk: string}
     */
    private function applyDomain(ConfigRevision $revision, string $tenantCode): array
    {
        $node = $revision->node;
        $row = $this->compiler->domain($node, $tenantCode);
        $state = $row === null ? 'removed' : 'active';

        $revision->update(['status' => RevisionStatus::Validated, 'validation_report' => ['ok' => true, 'state' => $state]]);

        $this->openSips->replaceDomain($node, $tenantCode, $row);

        if ($this->openSips->domain($node, $tenantCode) !== $row) {
            throw new ReadBackMismatch("The domain of tenant {$tenantCode} read back from {$node->name} differs from the one written.");
        }

        return [
            'checksum' => hash('sha256', (string) json_encode($row)),
            'compiled' => ['domain' => $row],
            'state' => $state,
            'asterisk' => 'unchecked',
        ];
    }

    /**
     * The tenant's phones need its domain on the node; written with each of its numbers, so a
     * reinstalled node gets it back with the first number written again.
     */
    private function ensureDomain(PbxNode $node, Tenant $tenant): void
    {
        $row = $this->compiler->domain($node, $tenant->code);

        if ($row !== null && $this->openSips->domain($node, $tenant->code) !== $row) {
            $this->openSips->replaceDomain($node, $tenant->code, $row);
        }
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

        if (! $node->hasTenantDomains()) {
            throw ProvisioningRejected::because('node_capability', "Node {$node->name} serves one shared SIP domain; reinstall OpenSIPS and Asterisk from SIP Deployer, then sync.");
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
