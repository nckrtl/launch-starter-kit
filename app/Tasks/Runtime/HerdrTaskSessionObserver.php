<?php

declare(strict_types=1);

namespace App\Tasks\Runtime;

use App\Herdr\SocketClient;
use App\Herdr\SocketHerdrRuntime;
use App\Models\TaskWorkspace;
use App\Tasks\Landing\HerdrTaskLandingReviewer;
use App\Tasks\Landing\TaskLandingData as Data;
use LogicException;

final readonly class HerdrTaskSessionObserver implements TaskSessionObserver
{
    public function __construct(private LinuxTaskAgentProcess $processes) {}

    public function observe(TaskWorkspace $workspace, array $session, string $conversation, bool $yielded): array
    {
        $client = new SocketClient(Data::text($workspace->configuration, 'socket'), 5);
        $runtime = new SocketHerdrRuntime($client);
        $live = get_object_vars($runtime->getAgent(Data::text($session, 'agentName')));
        HerdrTaskLandingReviewer::assertIdentity($session, $live);
        if (($live['agentId'] !== null && $live['agentId'] !== $conversation)
            || ($yielded && ! in_array($live['agentStatus'], ['idle', 'done'], true))) {
            throw new LogicException('The exact resumed conversation must be present and yielded for reconnection.');
        }
        $snapshot = $runtime->snapshot();
        $owners = array_values(array_filter($snapshot->workspaces, fn ($item): bool => $item->workspaceId === $live['workspaceId']));
        $panes = array_values(array_filter($snapshot->panes, fn ($item): bool => $item->paneId === $live['paneId']));
        $agents = array_values(array_filter($snapshot->agents, fn ($item): bool => $item->name === $live['agentName']));
        if (count($owners) !== 1 || count($panes) !== 1 || count($agents) !== 1
            || $owners[0]->repositoryRoot !== $workspace->repository || $owners[0]->checkoutPath !== $workspace->worktree
            || $owners[0]->linkedWorktree !== true || $agents[0]->kind !== 'codex'
            || $live['workingDirectory'] !== $workspace->worktree || $panes[0]->workingDirectory !== $workspace->worktree
            || $agents[0]->workingDirectory !== $workspace->worktree) {
            throw new LogicException('The resumed agent must uniquely own its registered task worktree and pane.');
        }
        foreach (['workspaceId', 'tabId', 'paneId', 'terminalId'] as $key) {
            if ($panes[0]->{$key} !== $live[$key] || $agents[0]->{$key} !== $live[$key]) {
                throw new LogicException('Herdr process and pane observations disagree.');
            }
        }
        if ($yielded && array_any($snapshot->agents, fn ($item): bool => $item->workingDirectory === $workspace->worktree && $item->status === 'working')) {
            throw new LogicException('Yield all task worktree agents before recording reconnection.');
        }
        $response = $client->request('pane.process_info', ['pane_id' => $live['paneId']]);
        $info = Data::object($response['process_info'] ?? null);
        $entries = $info['foreground_processes'] ?? null;
        $arguments = $workspace->configuration['agent_arguments'] ?? null;
        if (($response['type'] ?? null) !== 'pane_process_info' || ($info['pane_id'] ?? null) !== $live['paneId']
            || ! is_array($entries) || ! array_is_list($entries) || ! is_int($info['shell_pid'] ?? null)
            || ! is_int($info['foreground_process_group_id'] ?? null) || ! is_array($arguments) || ! array_is_list($arguments)
            || array_any($arguments, fn (mixed $value): bool => ! is_string($value))) {
            throw new LogicException('Missing exact foreground process evidence or captured agent arguments.');
        }
        $expected = [...array_map(fn (string $value): string => str_replace('{worktree}', $workspace->worktree, $value), $arguments), 'resume', $conversation];
        $candidates = array_values(array_filter($entries, fn (mixed $entry): bool => is_array($entry) && ($entry['name'] ?? null) === 'codex'));
        if (count($candidates) !== 1) {
            throw new LogicException('Exactly one real foreground Codex binary must occupy the assigned pane.');
        }
        $candidate = Data::object($candidates[0]);
        if (! is_int($candidate['pid'] ?? null)) {
            throw new LogicException('Missing Codex process ID.');
        }
        $process = $this->processes->read($candidate['pid']);
        $shell = $this->processes->read($info['shell_pid']);
        $argv = $process['argv'];
        if (! is_array($argv) || array_slice($argv, 1) !== $expected || $argv !== ($candidate['argv'] ?? null)
            || $process['cwd'] !== $workspace->worktree || ($candidate['cwd'] ?? null) !== $workspace->worktree
            || ! is_string($process['executable']) || basename($process['executable']) !== 'codex'
            || ($argv[0] ?? null) !== $process['executable']
            || $process['tty'] !== $shell['tty'] || $process['terminal'] !== $shell['terminal']
            || $process['session_id'] !== $shell['session_id'] || $process['process_group'] !== $info['foreground_process_group_id']
            || $process['foreground_group'] !== $info['foreground_process_group_id']
            || $shell['foreground_group'] !== $info['foreground_process_group_id']) {
            throw new LogicException('Live Codex argv, process identity, PTY or checkout differs from the exact resumed assignment.');
        }
        $process['shell_pid'] = $shell['pid'];
        $process['shell_start_time'] = $shell['start_time'];
        $after = get_object_vars($runtime->getAgent(Data::text($session, 'agentName')));
        HerdrTaskLandingReviewer::assertIdentity($live, $after);
        if ($yielded && ! in_array($after['agentStatus'], ['idle', 'done'], true)) {
            throw new LogicException('The restored conversation started work during reconnection inspection.');
        }
        if ($this->processes->read($candidate['pid']) !== array_diff_key($process, ['shell_pid' => true, 'shell_start_time' => true])) {
            throw new LogicException('The resumed process changed during observation.');
        }

        return ['session' => $after, 'process' => $process];
    }
}
