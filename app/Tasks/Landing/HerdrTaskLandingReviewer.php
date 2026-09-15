<?php

declare(strict_types=1);

namespace App\Tasks\Landing;

use App\Herdr\SocketClient;
use App\Herdr\SocketHerdrRuntime;
use App\Models\TaskWorkspace;
use App\Tasks\Runtime\TaskAgentSessions;
use LogicException;

final readonly class HerdrTaskLandingReviewer implements TaskLandingReviewer
{
    public function __construct(private TaskAgentSessions $sessions) {}

    public function promptOnce(TaskWorkspace $workspace, array $session, string $prompt): array
    {
        TaskLandingReviewTransport::inspect($session, $prompt);
        $runtime = new SocketHerdrRuntime(new SocketClient(TaskLandingData::text($workspace->configuration, 'socket'), 30));
        $name = TaskLandingData::text($session, 'agentName');
        $before = $runtime->getAgent($name);
        self::assertIdentity($session, get_object_vars($before));
        if (! in_array($before->agentStatus, ['idle', 'done'], true) || $before->workingDirectory !== $workspace->worktree) {
            throw new LogicException('The retained recovery reviewer must still own this worktree and yield.');
        }
        $this->sessions->assertProcess($workspace, $session);
        $after = $runtime->promptAgentOnce($name, $prompt);
        self::assertIdentity(get_object_vars($before), get_object_vars($after));

        return self::identity(get_object_vars($after));
    }

    public function observe(TaskWorkspace $workspace, array $session, bool $yielded): array
    {
        $runtime = new SocketHerdrRuntime(new SocketClient(TaskLandingData::text($workspace->configuration, 'socket'), 5));
        $name = TaskLandingData::text($session, 'agentName');
        $snapshot = $runtime->snapshot();
        $live = $runtime->getAgent($name);
        self::assertIdentity($session, get_object_vars($live));
        $workspaces = array_values(array_filter($snapshot->workspaces, fn ($item): bool => $item->workspaceId === $live->workspaceId));
        $panes = array_values(array_filter($snapshot->panes, fn ($item): bool => $item->paneId === $live->paneId));
        $agents = array_values(array_filter($snapshot->agents, fn ($item): bool => $item->name === $name));
        if (count($workspaces) !== 1 || count($panes) !== 1 || count($agents) !== 1) {
            throw new LogicException('The retained package reviewer is missing or ambiguous.');
        }
        $registered = $workspaces[0];
        $pane = $panes[0];
        $agent = $agents[0];
        if ($registered->repositoryRoot !== $workspace->repository || $registered->checkoutPath !== $workspace->worktree
            || $registered->linkedWorktree !== true || $pane->workingDirectory !== $workspace->worktree
            || $agent->workingDirectory !== $workspace->worktree || $live->workingDirectory !== $workspace->worktree
            || $agent->kind !== ($workspace->configuration['agent_kind'] ?? null)
            || ($yielded && (! in_array($live->agentStatus, ['idle', 'done'], true) || ! in_array($agent->status, ['idle', 'done'], true)))) {
            throw new LogicException('The exact retained package reviewer must own this worktree and yield before a new assignment.');
        }
        foreach (['workspaceId', 'tabId', 'paneId', 'terminalId'] as $key) {
            if ($pane->{$key} !== $agent->{$key} || $pane->{$key} !== $live->{$key}) {
                throw new LogicException('The reviewer snapshot and lookup disagree.');
            }
        }
        foreach ($snapshot->agents as $other) {
            if ($other->name !== $name && $other->workingDirectory === $workspace->worktree && $other->status === 'working') {
                throw new LogicException('Another working agent still owns the candidate checkout.');
            }
        }

        return self::identity(get_object_vars($live));
    }

    /** @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public static function identity(array $session): array
    {
        $identity = [];
        foreach (['workspaceId', 'tabId', 'paneId', 'terminalId', 'agentName', 'workingDirectory'] as $key) {
            $identity[$key] = TaskLandingData::text($session, $key);
        }
        $native = $session['agentId'] ?? null;
        if ($native !== null && (! is_string($native) || $native === '')) {
            throw new LogicException('Invalid native reviewer identity.');
        }
        $identity['agentId'] = $native;

        return $identity;
    }

    /** @param array<string, mixed> $expected
     * @param  array<string, mixed>  $observed
     */
    public static function assertIdentity(array $expected, array $observed): void
    {
        $expected = self::identity($expected);
        $observed = self::identity($observed);
        if ($expected['agentId'] === null) {
            $observed['agentId'] = null;
        }
        if ($observed !== $expected) {
            throw new LogicException('The assigned reviewer identity changed; no replacement conversation is authorized.');
        }
    }
}
