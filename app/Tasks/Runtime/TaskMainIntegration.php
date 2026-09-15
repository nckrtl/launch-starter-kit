<?php

declare(strict_types=1);

namespace App\Tasks\Runtime;

use App\Models\TaskFinalContinuation;
use App\Models\TaskRun;
use App\Models\TaskWorkspace;
use App\Tasks\GitObjectId;
use App\Tasks\Orbit\OrbitTaskProfile;
use LogicException;

final readonly class TaskMainIntegration
{
    /** @return array{audit_id:int, base_sha:string, main_sha:string, parents:list<string>, held_preflight:array<string, mixed>}|null */
    public function binding(TaskRun $run): ?array
    {
        if (! $run->getConnection()->getSchemaBuilder()->hasTable('task_final_continuations')) {
            return null;
        }
        $audit = TaskFinalContinuation::on($run->getConnectionName())->where('task_id', $run->task_id)
            ->where('mode', 'integrate_main')->first();
        if ($audit === null) {
            return null;
        }
        $workspace = TaskWorkspace::on($run->getConnectionName())->findOrFail($audit->task_workspace_id);
        $main = $audit->request['main_sha'] ?? null;
        $requestHash = hash('sha256', json_encode([$workspace->id, $audit->task_agent_dispatch_id,
            $audit->head, $audit->previous_manifest_hash, $audit->request], JSON_THROW_ON_ERROR));
        $dispatch = $workspace->dispatches()->findOrFail($audit->task_agent_dispatch_id);
        if (! is_string($main) || ! hash_equals($audit->request_hash, $requestHash)
            || $run->root_task_id !== $workspace->root_task_id || $run->base_sha !== $audit->head
            || ($run->input['workspace_id'] ?? null) !== $workspace->id
            || (OrbitTaskProfile::forWorkspace($workspace)['flow'] ?? null) !== 'proof'
            || $dispatch->state !== 'integration_required' || $dispatch->final_preflight_version !== 1
            || ($dispatch->final_preflight['head'] ?? null) !== $audit->head
            || ! is_string($dispatch->final_preflight['main_sha'] ?? null)
            || ($dispatch->final_preflight['manifest_hash'] ?? null) !== $audit->previous_manifest_hash
            || $audit->previous_manifest === null || $audit->manifest === null
            || TaskManifestAmendmentHistory::hash($audit->previous_manifest) !== $audit->previous_manifest_hash
            || TaskManifestAmendmentHistory::hash($audit->manifest) !== $audit->manifest_hash) {
            throw new LogicException('The main integration assignment does not match its immutable audit and held preflight.');
        }
        GitObjectId::validate($main);
        if ($main === $audit->head) {
            throw new LogicException('An integration requires distinct ordered parents.');
        }

        return ['audit_id' => $audit->id, 'base_sha' => $audit->head, 'main_sha' => $main, 'parents' => [$audit->head, $main],
            'held_preflight' => $dispatch->final_preflight];
    }
}
