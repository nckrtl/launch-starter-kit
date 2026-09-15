<?php

declare(strict_types=1);

namespace App\Tasks\Runtime;

use App\Models\TaskAgentDispatch;
use App\Models\TaskFinalContinuation;
use App\Models\TaskManifestAmendment;
use App\Models\TaskRun;
use App\Models\TaskWorkspace;
use App\Tasks\GitObjectId;
use App\Tasks\Orbit\OrbitTaskProfile;
use Illuminate\Support\Facades\Schema;
use LogicException;

final readonly class TaskManifestAmendmentHistory
{
    /** @param array<string, mixed> $currentManifest
     * @return list<array<string, mixed>>
     */
    public function ledger(TaskWorkspace $workspace, array $currentManifest): array
    {
        if (! Schema::hasTable('task_manifest_amendments')) {
            return [];
        }
        $audits = TaskManifestAmendment::on($workspace->getConnectionName())
            ->where('task_workspace_id', $workspace->id)->orderBy('sequence')->get();
        $expected = $workspace->manifest_hash;
        $amendedManifest = $this->beforeFinalContinuations($workspace, $currentManifest);
        $history = [];
        foreach ($audits as $index => $audit) {
            $targetVersion = $audit->request['expected_target_version'] ?? null;
            if (! is_string($targetVersion) || $audit->sequence !== $index + 1 || ! hash_equals($expected, $audit->previous_manifest_hash)
                || ! hash_equals(self::hash($audit->previous_manifest), $audit->previous_manifest_hash)
                || ! hash_equals(self::hash($audit->manifest), $audit->manifest_hash)
                || ! hash_equals($audit->proposal_hash, $audit->request_hash)
                || ($audit->request['amendment_key'] ?? null) !== $audit->amendment_key
                || ! hash_equals(self::requestHash($workspace->id, $audit->target_task_id, $audit->active_task_run_id,
                    $audit->task_agent_dispatch_id, $audit->previous_manifest_hash,
                    $targetVersion, $audit->request), $audit->request_hash)
                || ! hash_equals(self::auditHash($audit->toArray()), $audit->audit_hash)
                || ! $this->exactSplit($audit)) {
                throw new LogicException('Task manifest amendment history is inconsistent.');
            }
            GitObjectId::validate($audit->head);
            $expected = $audit->manifest_hash;
            $history[] = ['id' => $audit->id, 'sequence' => $audit->sequence, 'target_task_id' => $audit->target_task_id,
                'active_task_run_id' => $audit->active_task_run_id, 'task_agent_dispatch_id' => $audit->task_agent_dispatch_id,
                'head' => $audit->head, 'previous_manifest_hash' => $audit->previous_manifest_hash,
                'manifest_hash' => $audit->manifest_hash, 'reason' => $audit->request['reason'] ?? null,
                'evidence' => $audit->request['evidence'] ?? null, 'audit_hash' => $audit->audit_hash];
        }
        $hasContinuations = Schema::hasTable('task_final_continuations') && TaskFinalContinuation::on($workspace->getConnectionName())
            ->where('task_workspace_id', $workspace->id)->exists();
        if (($audits->isNotEmpty() || $hasContinuations) && (! hash_equals($expected, self::hash($amendedManifest))
            || ($audits->isNotEmpty() && $audits->last()->manifest !== $amendedManifest))) {
            throw new LogicException('The current task manifest does not match its amendment history.');
        }

        return $history;
    }

    /** @return list<array<string, mixed>> */
    public function continuations(TaskWorkspace $workspace): array
    {
        if (! Schema::hasTable('task_final_continuations') || ! TaskFinalContinuation::on($workspace->getConnectionName())
            ->where('task_workspace_id', $workspace->id)->where('mode', 'integrate_main')->exists()) {
            return [];
        }
        $history = [];
        foreach (TaskFinalContinuation::on($workspace->getConnectionName())->where('task_workspace_id', $workspace->id)
            ->orderBy('final_round')->get() as $continuation) {
            $history[] = $continuation->toArray();
        }

        return $history;
    }

    /** @param array<string, mixed> $request */
    public static function requestHash(int $workspaceId, int $targetId, int $runId, int $dispatchId,
        string $manifestHash, string $targetVersion, array $request): string
    {
        return self::hash([$workspaceId, $targetId, $runId, $dispatchId, $manifestHash, $targetVersion, $request]);
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $values */
    public static function auditHash(array $values): string
    {
        return self::hash(array_intersect_key($values, array_flip([
            'task_workspace_id', 'active_task_run_id', 'task_agent_dispatch_id', 'target_task_id', 'sequence',
            'amendment_key', 'proposal_hash', 'request_hash', 'head', 'previous_manifest_hash', 'manifest_hash',
            'previous_manifest', 'manifest', 'request',
        ])));
    }

    private function exactSplit(TaskManifestAmendment $audit): bool
    {
        $before = $audit->previous_manifest;
        $after = $audit->manifest;
        $replacements = $audit->request['replacements'] ?? null;
        $beforeRoot = $before['root'] ?? null;
        $beforeChildren = $before['children'] ?? null;
        $afterChildren = $after['children'] ?? null;
        if (! is_array($beforeRoot) || $beforeRoot !== ($after['root'] ?? null) || ! is_array($beforeChildren)
            || ! array_is_list($beforeChildren) || ! is_array($afterChildren) || ! array_is_list($afterChildren)
            || ! is_array($replacements) || ! array_is_list($replacements) || count($replacements) < 2
            || count($beforeChildren) < 2 || count($afterChildren) !== count($beforeChildren) + count($replacements) - 1) {
            return false;
        }
        $target = $beforeChildren[array_key_last($beforeChildren)];
        $prefix = array_slice($beforeChildren, 0, -1);
        if (($target['id'] ?? null) !== $audit->target_task_id || array_slice($afterChildren, 0, count($prefix)) !== $prefix) {
            return false;
        }
        $previous = $prefix[count($prefix) - 1];
        if (! is_array($previous)) {
            return false;
        }
        $rootId = $beforeRoot['id'] ?? null;
        $projectId = $beforeRoot['project_id'] ?? null;
        $previousId = $previous['id'] ?? null;
        $run = TaskRun::query()->find($audit->active_task_run_id);
        $dispatch = TaskAgentDispatch::query()->find($audit->task_agent_dispatch_id);
        if ($run === null || $dispatch === null || $run->task_id !== $previousId || $run->root_task_id !== $rootId
            || $dispatch->task_workspace_id !== $audit->task_workspace_id || $dispatch->task_run_id !== $run->id) {
            return false;
        }
        foreach ($replacements as $index => $replacement) {
            $child = $afterChildren[count($prefix) + $index] ?? null;
            if (! is_array($replacement) || ! is_array($child)
                || ($child['project_id'] ?? null) !== $projectId || ($child['parent_id'] ?? null) !== $rootId
                || ($child['kind'] ?? null) !== 'executable' || ($child['dependencies'] ?? null) !== [$previousId]
                || ($child['title'] ?? null) !== ($replacement['title'] ?? null)
                || ($child['description'] ?? null) !== ($replacement['description'] ?? null)
                || ($child['acceptance_criteria'] ?? null) !== ($replacement['acceptance_criteria'] ?? null)
                || ($index === 0 && ($child['id'] ?? null) !== $audit->target_task_id)) {
                return false;
            }
            $previousId = $child['id'] ?? null;
        }

        return true;
    }

    /** @param array<string, mixed> $currentManifest
     * @return array<string, mixed>
     */
    private function beforeFinalContinuations(TaskWorkspace $workspace, array $currentManifest): array
    {
        if (! Schema::hasTable('task_final_continuations')) {
            return $currentManifest;
        }
        $continuations = TaskFinalContinuation::on($workspace->getConnectionName())
            ->where('task_workspace_id', $workspace->id)->orderByDesc('final_round')->get();
        $manifest = $currentManifest;
        $round = $continuations->count();
        foreach ($continuations as $continuation) {
            if ($continuation->final_round !== $round--
                || ($continuation->request['mode'] ?? null) !== $continuation->mode
                || ! hash_equals($continuation->request_hash, self::hash([$workspace->id,
                    $continuation->task_agent_dispatch_id, $continuation->head,
                    $continuation->previous_manifest_hash, $continuation->request]))
                || ! hash_equals(self::hash($manifest), $continuation->manifest_hash)
                || ($continuation->manifest !== null && $continuation->manifest !== $manifest)) {
                throw new LogicException('Task final continuation history is inconsistent with its manifest amendment.');
            }
            if (in_array($continuation->mode, ['append_correction', 'integrate_main'], true)) {
                $children = $manifest['children'] ?? null;
                $brief = $continuation->request['task'] ?? null;
                if (! is_array($children) || ! array_is_list($children)) {
                    throw new LogicException('Task final continuation history is inconsistent with its manifest amendment.');
                }
                $tail = array_pop($children);
                $manifest['children'] = $children;
                if (! is_array($tail) || ($tail['id'] ?? null) !== $continuation->task_id
                    || ($continuation->mode === 'append_correction' && (! is_array($brief)
                        || ($tail['title'] ?? null) !== ($brief['title'] ?? null)
                        || ($tail['description'] ?? null) !== ($brief['description'] ?? null)
                        || ($tail['acceptance_criteria'] ?? null) !== ($brief['acceptance_criteria'] ?? null)))) {
                    throw new LogicException('Task final continuation history is inconsistent with its manifest amendment.');
                }
                if ($continuation->mode === 'integrate_main') {
                    $this->assertIntegration($workspace, $continuation, $manifest, $tail);
                }
            } elseif ($continuation->mode !== 'retry_final_checks' || $continuation->task_id !== null) {
                throw new LogicException('Task final continuation history is inconsistent with its manifest amendment.');
            }
            if (! hash_equals(self::hash($manifest), $continuation->previous_manifest_hash)
                || ($continuation->previous_manifest !== null && $continuation->previous_manifest !== $manifest)) {
                throw new LogicException('Task final continuation history is inconsistent with its manifest amendment.');
            }
        }

        return $manifest;
    }

    /** @param array<string, mixed> $before
     * @param  array<array-key, mixed>  $tail
     */
    private function assertIntegration(TaskWorkspace $workspace, TaskFinalContinuation $audit, array $before, array $tail): void
    {
        $main = $audit->request['main_sha'] ?? null;
        $dispatch = $workspace->dispatches()->find($audit->task_agent_dispatch_id);
        $children = $before['children'] ?? [];
        $previous = is_array($children) && $children !== [] ? $children[array_key_last($children)] : null;
        if (! is_string($main) || $main === $audit->head || $audit->previous_manifest === null || $audit->manifest === null
            || (OrbitTaskProfile::forWorkspace($workspace)['flow'] ?? null) !== 'proof'
            || $dispatch === null || $dispatch->kind !== 'final_review' || $dispatch->state !== 'integration_required'
            || $dispatch->final_preflight_version !== 1 || $dispatch->round !== $audit->final_round - 1
            || ($dispatch->final_preflight['head'] ?? null) !== $audit->head
            || ! is_string($dispatch->final_preflight['main_sha'] ?? null)
            || ($dispatch->final_preflight['main_is_ancestor'] ?? null) !== false
            || ($dispatch->final_preflight['manifest_hash'] ?? null) !== $audit->previous_manifest_hash
            || ! is_array($previous) || ($tail['dependencies'] ?? null) !== [$previous['id'] ?? null]
            || ($tail['project_id'] ?? null) !== $workspace->project_id
            || ($tail['parent_id'] ?? null) !== $workspace->root_task_id || ($tail['kind'] ?? null) !== 'executable') {
            throw new LogicException('Task main integration history does not match its retained preflight and appended task.');
        }
        GitObjectId::validate($audit->head);
        GitObjectId::validate($main);
    }
}
