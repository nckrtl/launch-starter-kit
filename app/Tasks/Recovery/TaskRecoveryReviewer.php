<?php

declare(strict_types=1);

namespace App\Tasks\Recovery;

use App\Herdr\SocketClient;
use App\Herdr\SocketHerdrRuntime;
use App\Models\TaskWorkspace;
use LogicException;

final class TaskRecoveryReviewer
{
    /** @return array<string, mixed> */
    public function verify(TaskWorkspace $workspace, TaskRecoveryEvidence $evidence): array
    {
        $identity = null;
        foreach ($evidence->children as $child) {
            $observed = TaskRecoveryEvidence::object($child['provenance']['observed_reviewer'] ?? null);
            $current = [];
            foreach (['reviewer_ref', 'codex_session', 'herdr_workspace', 'pane', 'session_path'] as $key) {
                $current[$key] = TaskRecoveryEvidence::string($observed, $key);
            }
            if ($identity !== null && $identity !== $current) {
                throw new LogicException('Every recovered child must identify the same retained reviewer conversation.');
            }
            $identity = $current;
        }
        if ($identity === null) {
            throw new LogicException('The retained reviewer identity is missing.');
        }
        $runtime = new SocketHerdrRuntime(new SocketClient(TaskRecoveryEvidence::string($workspace->configuration, 'socket'), 5));
        $snapshot = $runtime->snapshot();
        $workspaces = array_values(array_filter($snapshot->workspaces, fn ($item): bool => $item->workspaceId === $identity['herdr_workspace']));
        $panes = array_values(array_filter($snapshot->panes, fn ($item): bool => $item->paneId === $identity['pane']));
        $agents = array_values(array_filter($snapshot->agents, fn ($item): bool => $item->name === $identity['reviewer_ref']));
        if (count($workspaces) !== 1 || count($panes) !== 1 || count($agents) !== 1) {
            throw new LogicException('The retained reviewer workspace, pane, or agent is missing or ambiguous.');
        }
        $registered = $workspaces[0];
        $pane = $panes[0];
        $agent = $agents[0];
        $live = $runtime->getAgent($identity['reviewer_ref']);
        if ($registered->repositoryRoot !== $workspace->repository || $registered->checkoutPath !== $workspace->worktree
            || $registered->linkedWorktree !== true || $pane->workspaceId !== $registered->workspaceId
            || $pane->workingDirectory !== $workspace->worktree || $agent->workingDirectory !== $workspace->worktree
            || $agent->kind !== $workspace->configuration['agent_kind']
            || $live->workingDirectory !== $workspace->worktree || $live->agentName !== $identity['reviewer_ref']
            || $live->agentId !== $identity['codex_session']) {
            throw new LogicException('The live retained reviewer identity differs from recovery provenance.');
        }
        foreach (['workspaceId', 'tabId', 'paneId', 'terminalId'] as $key) {
            if ($pane->{$key} !== $agent->{$key} || $pane->{$key} !== $live->{$key}) {
                throw new LogicException('Herdr snapshot and agent lookup disagree on the retained reviewer identity.');
            }
        }

        return get_object_vars($live);
    }
}
