<?php

namespace App\Modules\Provisioning\Actions;

use App\Modules\Infrastructure\Asterisk\RealtimeAdapter;
use App\Modules\Infrastructure\OpenSips\OpenSipsDbAdapter;
use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Provisioning\Compiler\CallingCompiler;
use App\Modules\Provisioning\Compiler\CompiledNumber;
use App\Modules\Provisioning\Compiler\NumberCompiler;
use App\Modules\Provisioning\Enums\OperationType;
use App\Modules\Provisioning\Enums\ReconcileStatus;
use App\Modules\Provisioning\Enums\RevisionStatus;
use App\Modules\Provisioning\ErrorClassifier;
use App\Modules\Provisioning\Models\ConfigRevision;
use App\Modules\Provisioning\Models\ReconcileRun;
use App\Modules\Provisioning\NodeReadBack;
use App\Modules\Provisioning\ReconcileFindings;
use App\Modules\Telephony\Models\DialNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Compares what a node holds with the desired state of every tenant on it (plan v0.2 §6.4), e.g.
 * after SIP Deployer reinstalled OpenSIPS or Asterisk and their databases came back empty.
 *
 * A target whose rows are missing or changed is written again through the usual pipeline, so the
 * fix is retried, read back and listed under Operations like any other change. Rows nobody asked
 * for (an exten, subscriber or domain no target owns) are only reported: they may be someone's
 * test rows. Extens under the prefixes a tenant's calling dialplan owns belong to that target, so
 * an extra one there counts as a change of it. Targets with a revision on its way are skipped.
 */
class ReconcileNode
{
    /** At most this many drift entries are kept per run; the counts cover them all. */
    public const MAX_DRIFT_ENTRIES = 500;

    public function __construct(
        private NumberCompiler $numbers,
        private CallingCompiler $calling,
        private NodeReadBack $readBack,
        private RealtimeAdapter $asterisk,
        private OpenSipsDbAdapter $openSips,
        private RequestProvisioning $provisioning,
        private ErrorClassifier $classifier,
    ) {}

    /**
     * Whether the node is one this application writes to, so its rows can be compared.
     */
    public static function canReconcile(PbxNode $node): bool
    {
        return $node->removed_at === null
            && $node->hasOpenSips()
            && in_array('realtime', $node->asteriskFeatures(), true)
            && $node->hasTenantDomains();
    }

    public function handle(PbxNode $node, string $trigger, bool $fix = true): ReconcileRun
    {
        $run = ReconcileRun::query()->create(['pbx_node_id' => $node->id, 'trigger' => $trigger, 'started_at' => now()]);

        if (! self::canReconcile($node)) {
            $run->update(['status' => ReconcileStatus::Failed, 'error' => "Node {$node->name} is removed or does not serve a SIP domain per tenant.", 'finished_at' => now()]);

            return $run;
        }

        $findings = new ReconcileFindings;

        try {
            $checked = $this->compare($node, $findings);
        } catch (Throwable $exception) {
            $error = $this->classifier->classify($exception);
            Log::warning('Reconciling a node failed.', ['pbx_node_id' => $node->id, 'error_code' => $error['code']]);
            $run->update(['status' => ReconcileStatus::Failed, 'error' => $error['message'], 'finished_at' => now()]);

            return $run;
        }

        $run->update([
            'status' => $findings->drift === [] ? ReconcileStatus::Clean : ReconcileStatus::Drift,
            'targets_checked' => $checked,
            'missing_count' => $findings->count('missing'),
            'changed_count' => $findings->count('changed'),
            'unexpected_count' => $findings->count('unexpected'),
            'fixed_count' => $fix ? $this->fix($node, $findings) : 0,
            'drift' => array_slice($findings->drift, 0, self::MAX_DRIFT_ENTRIES),
            'finished_at' => now(),
        ]);

        return $run;
    }

    /**
     * @return int the number of targets compared
     */
    private function compare(PbxNode $node, ReconcileFindings $findings): int
    {
        $inFlight = ConfigRevision::query()
            ->where('pbx_node_id', $node->id)
            ->whereIn('status', [RevisionStatus::Draft, RevisionStatus::Validated, RevisionStatus::Applying])
            ->pluck('target')
            ->flip()
            ->all();

        $checked = 0;
        $expectedExtens = [NumberCompiler::DENIED_EXTEN => true];
        $expectedDomainCodes = [];

        $assignments = TenantPbxAssignment::query()->where('pbx_node_id', $node->id)->with('tenant')->orderBy('id')->get();

        // shared by every tenant; any tenant's calling dialplan writes it again
        if ($assignments->isNotEmpty()) {
            $first = $assignments->first();
            $denied = [NumberCompiler::DENIED_EXTEN => $this->numbers->denied()];
            $actual = [NumberCompiler::DENIED_EXTEN => $this->asterisk->exten($node, NumberCompiler::DENIED_EXTEN)];

            if ($this->extensDiffer($findings, ConfigRevision::callingTarget($first->tenant->code), [NumberCompiler::DENIED_EXTEN], $denied, $actual)) {
                $this->fixCalling($findings, $first);
            }
        }

        foreach ($assignments as $assignment) {
            $tenant = $assignment->tenant;
            $code = $tenant->code;
            $expectedDomainCodes[$code] = true;

            $target = ConfigRevision::domainTarget($code);

            if (! isset($inFlight[$target])) {
                $checked++;
                $desired = $this->numbers->domain($node, $code);
                $actual = $this->openSips->domain($node, $code);

                if ($actual !== $desired) {
                    $findings->record($actual === null ? 'missing' : 'changed', $target, "domain {$tenant->sip_domain}");
                    $findings->fix($target, ['kind' => 'domain', 'tenant_id' => $tenant->id, 'aggregate_type' => TenantPbxAssignment::MORPH, 'aggregate_id' => $assignment->id]);
                }
            }

            $compiledCalling = $this->calling->compile($node, $code);
            $actualCalling = $this->readBack->calling($node, $compiledCalling);
            $expectedExtens += array_fill_keys(array_map(strval(...), array_keys($compiledCalling->extens)), true);
            $expectedExtens += array_fill_keys(array_map(strval(...), array_keys($actualCalling)), true);
            $target = ConfigRevision::callingTarget($code);

            if (! isset($inFlight[$target])) {
                $checked++;

                if ($this->extensDiffer($findings, $target, array_keys($compiledCalling->extens), $compiledCalling->extens, $actualCalling)) {
                    $this->fixCalling($findings, $assignment);
                }
            }

            $dialNumbers = DialNumber::withoutTenantScope()->where('tenant_id', $tenant->id)->where('pbx_node_id', $node->id)->orderBy('number')->get();
            $expectedUsernames = [];

            foreach ($dialNumbers as $dialNumber) {
                $compiled = $this->numbers->compile($node, $tenant->routingNumber($dialNumber->number));
                $target = ConfigRevision::numberTarget($compiled->number);
                $expectedExtens[$compiled->number] = true;
                $expectedExtens[$compiled->permissionExten()] = true;

                if ($compiled->username !== null) {
                    $expectedUsernames[$compiled->username] = true;
                }

                if (isset($inFlight[$target])) {
                    continue;
                }

                $checked++;

                if ($this->numberDiffers($findings, $target, $compiled, $this->readBack->number($node, $compiled))) {
                    $findings->fix($target, ['kind' => 'number', 'tenant_id' => $tenant->id, 'aggregate_type' => $dialNumber->owner_type, 'aggregate_id' => $dialNumber->owner_id, 'number' => $dialNumber->number]);
                }
            }

            // written with each of the tenant's numbers, so the first one brings it back
            if ($node->hasVoicemail() && $dialNumbers->isNotEmpty()) {
                $exten = $tenant->routingNumber(NumberCompiler::VOICEMAIL_MAIN_EXTEN);
                $expectedExtens[$exten] = true;
                $actual = $this->asterisk->exten($node, $exten);
                $first = $dialNumbers->first();
                $target = ConfigRevision::numberTarget($tenant->routingNumber($first->number));

                if (! isset($inFlight[$target]) && $actual !== $this->numbers->voicemailMain($tenant)) {
                    $findings->record($actual === [] ? 'missing' : 'changed', $target, "exten {$exten}");
                    $findings->fix($target, ['kind' => 'number', 'tenant_id' => $tenant->id, 'aggregate_type' => $first->owner_type, 'aggregate_id' => $first->owner_id, 'number' => $first->number]);
                }
            }

            foreach ($this->openSips->usernamesIn($node, $tenant->sip_domain) as $username) {
                if (! isset($expectedUsernames[$username])) {
                    $findings->record('unexpected', null, "subscriber {$username}@{$tenant->sip_domain}");
                }
            }
        }

        foreach ($this->openSips->domains($node) as $row) {
            if (! isset($expectedDomainCodes[$row['attrs']])) {
                $findings->record('unexpected', null, "domain {$row['domain']} (attrs {$row['attrs']})");
            }
        }

        foreach ($this->asterisk->extenNames($node) as $exten) {
            if (! isset($expectedExtens[$exten])) {
                $findings->record('unexpected', null, "exten {$exten}");
            }
        }

        return $checked;
    }

    /**
     * Records each exten that is missing, changed or there without being wanted.
     *
     * @param  list<string|int>  $names  the extens wanted
     * @param  array<string, list<array{priority: int, app: string, appdata: string}>>  $desired
     * @param  array<string, list<array{priority: int, app: string, appdata: string}>>  $actual
     */
    private function extensDiffer(ReconcileFindings $findings, string $target, array $names, array $desired, array $actual): bool
    {
        $differs = false;

        foreach (array_unique(array_map(strval(...), [...$names, ...array_keys($actual)])) as $exten) {
            $wanted = $desired[$exten] ?? [];
            $held = $actual[$exten] ?? [];

            if ($wanted !== $held) {
                $differs = true;
                $findings->record($held === [] ? 'missing' : 'changed', $target, $wanted === [] ? "exten {$exten} (not wanted)" : "exten {$exten}");
            }
        }

        return $differs;
    }

    private function numberDiffers(ReconcileFindings $findings, string $target, CompiledNumber $desired, CompiledNumber $actual): bool
    {
        if ($actual->checksum() === $desired->checksum()) {
            return false;
        }

        $parts = [
            "subscriber {$desired->username}@{$desired->sipDomain}" => [$desired->subscriber, $actual->subscriber],
            "exten {$desired->number}" => [$desired->dialplan, $actual->dialplan],
            "mailbox {$desired->username}@{$desired->voicemailContext}" => [$desired->voicemail, $actual->voicemail],
            "exten {$desired->permissionExten()}" => [$desired->permissions, $actual->permissions],
        ];

        foreach ($parts as $item => [$wanted, $held]) {
            if ($wanted !== $held) {
                $findings->record($held === null || $held === [] ? 'missing' : 'changed', $target, $item);
            }
        }

        return true;
    }

    private function fixCalling(ReconcileFindings $findings, TenantPbxAssignment $assignment): void
    {
        $findings->fix(ConfigRevision::callingTarget($assignment->tenant->code), [
            'kind' => 'calling',
            'tenant_id' => $assignment->tenant_id,
            'aggregate_type' => TenantPbxAssignment::MORPH,
            'aggregate_id' => $assignment->id,
        ]);
    }

    /**
     * Writes every drifted target again.
     *
     * @return int the number of targets asked for
     */
    private function fix(PbxNode $node, ReconcileFindings $findings): int
    {
        foreach ($findings->fixes as $fix) {
            DB::transaction(fn () => match ($fix['kind']) {
                'number' => $this->provisioning->forNumber($node->id, $fix['tenant_id'], (string) ($fix['number'] ?? ''), OperationType::Reconciled, $fix['aggregate_type'], $fix['aggregate_id']),
                'domain' => $this->provisioning->forDomain($node->id, $fix['tenant_id'], OperationType::Reconciled, $fix['aggregate_type'], $fix['aggregate_id']),
                'calling' => $this->provisioning->forCalling($node->id, $fix['tenant_id'], OperationType::Reconciled, $fix['aggregate_type'], $fix['aggregate_id']),
            });
        }

        return count($findings->fixes);
    }
}
