<?php

declare(strict_types=1);

namespace App\Tasks\Recovery;

use App\Jobs\AdvanceTaskRunner;
use App\Models\TaskRecovery;
use App\Models\TaskRecoveryResumption;
use App\Models\TaskWorkspace;
use App\Tasks\GitObjectId;
use App\Tasks\Runtime\TaskRuntimeLock;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final readonly class ResumeRecoveredTaskWorkspace
{
    public function __construct(private TaskResumptionDatabase $databases, private TaskRecoveryDatabase $offline,
        private TaskResumptionEvidence $evidence, private TaskRecoveryReviewer $reviewer, private TaskRuntimeLock $lock) {}

    /** @return array<string, mixed> */
    public function handle(int $workspaceId, string $database, string $recoveryId, string $head, bool $exclusive,
        bool $apply = false, bool $advance = false): array
    {
        if ($workspaceId < 1 || ! Str::isUuid($recoveryId) || ! $exclusive) {
            throw new LogicException('Resume requires a workspace, recovery UUID, and explicit exclusive ownership attestation.');
        }
        GitObjectId::validate($head);
        if ($advance && (! $apply || config('task-runtime.enabled') !== true)) {
            throw new LogicException('Advancement requires --apply and an enabled task runtime.');
        }
        $this->databases->assertConfigured($database);
        $this->offline->assertOfflineFile($database);
        $requestHash = hash('sha256', json_encode([$workspaceId, $database, $recoveryId, $head, $advance], JSON_THROW_ON_ERROR));
        if (! $apply) {
            $name = 'task-resume-preview-'.Str::uuid();
            $connection = DB::connectUsing($name, ['driver' => 'sqlite',
                'database' => 'file:'.str_replace('%2F', '/', rawurlencode($database)).'?mode=ro',
                'foreign_key_constraints' => true, 'transaction_mode' => 'DEFERRED']);
            try {
                if ($connection->scalar("SELECT file FROM pragma_database_list WHERE name = 'main'") !== $database) {
                    throw new LogicException('Preview connection differs from the pinned database.');
                }

                return $connection->transaction(fn (): array => $this->inspect($workspaceId, $database, $recoveryId, $head, $requestHash, $name));
            } finally {
                DB::purge($name);
            }
        }
        $this->databases->assertRuntimeConnection($database);
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Recovery resumption must own its outer database transaction.');
        }
        $result = $this->lock->handle($workspaceId, function () use ($workspaceId, $database, $recoveryId, $head, $requestHash, $advance): array {
            $this->databases->assertRuntimeConnection($database);
            $report = $this->inspect($workspaceId, $database, $recoveryId, $head, $requestHash);
            if ($report['resume_recorded'] === true) {
                return $report;
            }
            $workspace = TaskWorkspace::query()->findOrFail($workspaceId);
            $recovery = TaskRecovery::query()->findOrFail($recoveryId);
            $audit = TaskRecoveryResumption::query()->create([
                'id' => (string) Str::uuid(), 'task_workspace_id' => $workspaceId, 'task_recovery_id' => $recoveryId,
                'request_hash' => $requestHash, 'database_path' => $database, 'head' => $head,
                'manifest_hash' => $workspace->manifest_hash, 'previous_attention' => $workspace->attention,
                'reviewer_session' => $report['reviewer_session'], 'herdr_workspace' => $report['herdr_workspace'],
                'evidence' => $report['evidence'], 'advance_requested' => $advance, 'created_at' => now(),
            ]);
            $evidence = $this->evidence->verify($workspace->refresh(), $recovery->refresh(), $head);
            $current = $this->reviewer->verify($workspace, $evidence);
            $previous = TaskRecoveryEvidence::object($report['reviewer_session']);
            foreach (['workspaceId', 'tabId', 'paneId', 'terminalId', 'agentId', 'agentName', 'workingDirectory'] as $key) {
                if ($current[$key] !== $previous[$key]) {
                    throw new LogicException('The retained reviewer changed while recording resumption.');
                }
            }
            $workspace->update(['herdr_workspace' => $report['herdr_workspace'], 'reviewer_session' => $report['reviewer_session'], 'attention' => null]);

            return [...$report, 'mode' => 'apply', 'resume_recorded' => true, 'audit' => $audit->toArray(),
                'advancement' => $advance ? 'pending' : 'not_requested'];
        });
        if ($advance && ($result['mode'] ?? null) === 'apply') {
            try {
                Bus::dispatch(new AdvanceTaskRunner($workspaceId));
                $result['advancement'] = 'queued';
            } catch (Throwable $exception) {
                $result['advancement'] = 'pending';
                $result['message'] = 'Resume recorded; advancement pending. Inspect the queue failure, then use tasks:advance.';
                $result['enqueue_error'] = Str::limit($exception->getMessage(), 1000);
            }
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function inspect(int $workspaceId, string $database, string $recoveryId, string $head, string $requestHash, ?string $connection = null): array
    {
        $existing = TaskRecoveryResumption::on($connection)->where('task_workspace_id', $workspaceId)->first();
        if ($existing !== null) {
            if ($existing->task_recovery_id !== $recoveryId || ! hash_equals($existing->request_hash, $requestHash)) {
                throw new LogicException('This recovered workspace already has a conflicting resumption audit.');
            }

            return ['mode' => 'already_recorded', 'resume_recorded' => true, 'audit' => $existing->toArray(),
                'advancement' => 'unchanged', 'message' => 'Existing resumption returned; no work was enqueued.'];
        }
        $workspace = TaskWorkspace::on($connection)->findOrFail($workspaceId);
        $recovery = TaskRecovery::on($connection)->findOrFail($recoveryId);
        $evidence = $this->evidence->verify($workspace, $recovery, $head);
        if ($database === $evidence->backupPath) {
            throw new LogicException('The original backup cannot be the runtime resume database.');
        }
        $reviewer = $this->reviewer->verify($workspace, $evidence);

        return ['mode' => 'preview', 'resume_recorded' => false, 'database' => $database,
            'workspace_id' => $workspaceId, 'recovery_id' => $recoveryId, 'head' => $head, 'manifest_hash' => $evidence->manifestHash,
            'previous_attention' => $workspace->attention,
            'reviewer_session' => $reviewer,
            'herdr_workspace' => ['workspaceId' => $reviewer['workspaceId'], 'paneId' => $reviewer['paneId']],
            'evidence' => ['package_sha256' => $evidence->sha256, 'source_sha256' => $evidence->sourceSha256,
                'backup_sha256' => $evidence->backupSha256,
                'provenance_sha256' => hash('sha256', json_encode($recovery->getAttribute('provenance'), JSON_THROW_ON_ERROR)),
                'configuration_sha256' => hash('sha256', json_encode($workspace->configuration, JSON_THROW_ON_ERROR))],
            'root_completed' => false, 'final_check' => null, 'final_result' => null, 'advancement' => 'not_requested'];
    }
}
