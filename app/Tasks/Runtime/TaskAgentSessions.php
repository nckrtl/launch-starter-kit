<?php

declare(strict_types=1);

namespace App\Tasks\Runtime;

use App\Models\TaskSessionReconnection;
use App\Models\TaskWorkspace;
use App\Tasks\Landing\HerdrTaskLandingReviewer;
use App\Tasks\Landing\TaskLandingData as Data;
use LogicException;

final readonly class TaskAgentSessions
{
    public function __construct(private TaskSessionObserver $observer) {}

    /** @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function resolve(TaskWorkspace $workspace, array $session): array
    {
        $binding = $this->binding($workspace, $session);

        return $binding === null ? $session : Data::object($binding['current']);
    }

    /** @param array<string, mixed> $session */
    public function assertProcess(TaskWorkspace $workspace, array $session): void
    {
        $binding = $this->binding($workspace, $session);
        if ($binding === null) {
            return;
        }
        $observed = $this->observer->observe($workspace, Data::object($binding['current']), Data::text($binding, 'conversation_id'), false);
        if ($observed['process'] !== $binding['process']) {
            throw new LogicException('The audited resumed Codex process changed; no replacement will be prompted.');
        }
    }

    /** @param array<string, mixed> $session
     * @return array<string, mixed>|null
     */
    private function binding(TaskWorkspace $workspace, array $session): ?array
    {
        if (! $workspace->getConnection()->getSchemaBuilder()->hasTable('task_session_reconnections')) {
            return null;
        }
        $audit = TaskSessionReconnection::on($workspace->getConnectionName())->where('task_workspace_id', $workspace->id)->first();
        if ($audit === null) {
            return null;
        }
        foreach ($audit->sessions as $binding) {
            $binding = Data::object($binding);
            $old = Data::object($binding['previous']);
            $current = Data::object($binding['current']);
            if (($session['agentName'] ?? null) !== ($old['agentName'] ?? null)) {
                continue;
            }
            $identity = HerdrTaskLandingReviewer::identity($session);
            $before = HerdrTaskLandingReviewer::identity($old);
            $after = HerdrTaskLandingReviewer::identity($current);
            if ($identity !== $before && $identity !== $after && $identity['agentId'] === $binding['conversation_id']) {
                // A later Herdr observation may finally expose the already-proven ID.
                $identity['agentId'] = $after['agentId'];
            }
            if ($identity !== $before && $identity !== $after) {
                throw new LogicException('The session is not an exact original or effective reconnection binding.');
            }

            return $binding;
        }

        return null;
    }
}
