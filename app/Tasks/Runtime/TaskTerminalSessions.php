<?php

declare(strict_types=1);

namespace App\Tasks\Runtime;

use App\Models\Task;
use App\Models\TaskAgentDispatch;
use App\Models\TaskRun;
use App\Tasks\Enums\TaskKind;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class TaskTerminalSessions
{
    public function __construct(private TaskAgentSessions $sessions) {}

    /** @return list<array{role: string, label: string, status: string}> */
    public function overview(Task $task): array
    {
        $run = $this->latestRun($task);

        if ($run === null) {
            return [];
        }

        $sessions = [];

        foreach (['implementer' => 'Implementer', 'reviewer' => 'Reviewer'] as $role => $label) {
            $target = $this->targetForRun($run, $role);

            if ($target !== null) {
                $sessions[] = ['role' => $role, 'label' => $label, 'status' => $target->status];
            }
        }

        return $sessions;
    }

    public function resolve(Task $task, string $role): TaskTerminalTarget
    {
        if (! in_array($role, ['implementer', 'reviewer'], true)) {
            throw new NotFoundHttpException('Task session not found.');
        }

        $run = $this->latestRun($task);
        $target = $run === null ? null : $this->targetForRun($run, $role);

        if ($target === null) {
            throw new NotFoundHttpException('Task session not found.');
        }

        return $target;
    }

    private function latestRun(Task $task): ?TaskRun
    {
        return TaskRun::query()
            ->when(
                $task->kind === TaskKind::Group,
                fn (Builder $query): Builder => $query->where('root_task_id', $task->id),
                fn (Builder $query): Builder => $query->where('task_id', $task->id),
            )
            ->orderByDesc('id')
            ->first();
    }

    private function targetForRun(TaskRun $run, string $role): ?TaskTerminalTarget
    {
        $dispatch = TaskAgentDispatch::query()->with('workspace')->where('task_run_id', $run->id)
            ->whereNotNull('session')
            ->when(
                $role === 'implementer',
                fn (Builder $query): Builder => $query->where('kind', 'implement'),
                fn (Builder $query): Builder => $query->whereIn('kind', ['review', 'commit']),
            )
            ->orderByDesc('id')
            ->first();

        if ($dispatch === null || ! is_array($dispatch->session)) {
            return null;
        }

        $workspace = $dispatch->workspace;
        if ($workspace === null
            || ! is_int($workspace->orbit_node_id) || $workspace->orbit_node_id < 1
            || ! is_int($workspace->orbit_herdr_session_id) || $workspace->orbit_herdr_session_id < 1
            || ! is_string($workspace->orbit_herdr_session) || $workspace->orbit_herdr_session === ''
            || ! is_string($workspace->orbit_herdr_observer_origin) || $workspace->orbit_herdr_observer_origin === '') {
            return null;
        }
        $session = $this->sessions->resolve($workspace, $dispatch->session);
        $workspaceId = $session['workspaceId'] ?? null;
        $paneId = $session['paneId'] ?? null;
        $terminalId = $session['terminalId'] ?? null;
        $status = $session['agentStatus'] ?? 'unknown';
        $agentName = $session['agentName'] ?? null;
        $workingDirectory = $session['workingDirectory'] ?? null;

        if (! is_string($workspaceId) || $workspaceId === ''
            || ! is_string($paneId) || $paneId === ''
            || ! is_string($terminalId) || $terminalId === ''
            || ! is_string($status) || $status === '') {
            return null;
        }

        return new TaskTerminalTarget(
            $role,
            $workspaceId,
            $paneId,
            $terminalId,
            $status,
            is_string($agentName) && $agentName !== '' ? $agentName : null,
            is_string($workingDirectory) && $workingDirectory !== '' ? $workingDirectory : null,
            $workspace->orbit_herdr_session_id,
            $workspace->orbit_herdr_observer_origin,
            $workspace->orbit_node_id,
            $workspace->orbit_herdr_session,
        );
    }
}
