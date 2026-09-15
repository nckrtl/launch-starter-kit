<?php

declare(strict_types=1);

namespace App\Tasks\Runtime;

use App\Delivery\Data\HerdrAgentIdentifiers;
use App\Delivery\Data\HerdrAgentLaunch;
use App\Herdr\RequestFailed;
use App\Herdr\SocketClient;
use App\Herdr\SocketHerdrRuntime;
use App\Models\TaskWorkspace;
use Illuminate\Support\Sleep;
use LogicException;

final readonly class HerdrTaskAgents implements TaskAgents
{
    public function __construct(private TaskAgentSessions $sessions) {}

    private const int STARTUP_TIMEOUT_MILLISECONDS = 15_000;

    private const int PROMPT_RETRY_DELAY_MILLISECONDS = 250;

    public function assertSession(TaskWorkspace $workspace, array $session): void
    {
        $this->sessions->assertProcess($workspace, $session);
        $this->assertIdentity($workspace, $session, $this->runtime($workspace)->getAgent($this->string($session, 'agentName')));
    }

    public function start(TaskWorkspace $workspace, string $name): array
    {
        $arguments = $workspace->configuration['agent_arguments'] ?? null;
        if (! is_array($arguments) || ! array_is_list($arguments)) {
            throw new LogicException('Invalid agent arguments.');
        }
        foreach ($arguments as $argument) {
            if (! is_string($argument)) {
                throw new LogicException('Invalid agent argument.');
            }
        }
        $arguments = array_map(fn (string $argument): string => str_replace('{worktree}', $workspace->worktree, $argument), $arguments);

        $runtime = $this->runtime($workspace);
        $opened = $workspace->herdr_workspace;
        if ($opened === null) {
            $result = $runtime->openWorktree($workspace->repository, $workspace->worktree, 'Task '.$workspace->root_task_id);
            if ($result->alreadyOpen) {
                throw new LogicException('The worktree is already open in Herdr; inspect ownership before starting.');
            }
            $opened = ['workspaceId' => $result->workspaceId, 'paneId' => $result->paneId];
            $workspace->update(['herdr_workspace' => $opened]);
            $pane = $result->paneId;
        } else {
            $pane = $runtime->createTab(
                $this->string($opened, 'workspaceId'),
                $workspace->worktree,
                $name,
            )->paneId;
        }

        $agent = $runtime->startAgent($pane, $name, new HerdrAgentLaunch($this->string($workspace->configuration, 'agent_kind'), $arguments, self::STARTUP_TIMEOUT_MILLISECONDS));
        if ($agent->workingDirectory !== $workspace->worktree || $agent->agentName !== $name || $agent->paneId !== $pane
            || $agent->workspaceId !== $this->string($opened, 'workspaceId')) {
            throw new LogicException('Herdr started an agent outside the assigned execution context.');
        }

        return get_object_vars($agent);
    }

    public function prompt(TaskWorkspace $workspace, array $session, string $prompt): array
    {
        $runtime = $this->runtime($workspace);
        $name = $this->string($session, 'agentName');
        for ($waited = 0; ; $waited += self::PROMPT_RETRY_DELAY_MILLISECONDS) {
            $before = $runtime->getAgent($name);
            $this->assertIdentity($workspace, $session, $before);
            $session = get_object_vars($before);
            $this->sessions->assertProcess($workspace, $session);
            try {
                $after = $runtime->promptAgentOnce($name, $prompt);
            } catch (RequestFailed $exception) {
                if ($exception->errorCode !== 'agent_not_ready' || $waited >= self::STARTUP_TIMEOUT_MILLISECONDS) {
                    throw $exception;
                }
                Sleep::usleep(self::PROMPT_RETRY_DELAY_MILLISECONDS * 1000);

                continue;
            }
            $this->assertIdentity($workspace, $session, $after);

            return get_object_vars($after);
        }
    }

    private function runtime(TaskWorkspace $workspace): SocketHerdrRuntime
    {
        return new SocketHerdrRuntime(new SocketClient($this->string($workspace->configuration, 'socket'), 30));
    }

    /** @param array<string, mixed> $session */
    private function assertIdentity(TaskWorkspace $workspace, array $session, HerdrAgentIdentifiers $agent): void
    {
        foreach (['workspaceId', 'tabId', 'paneId', 'terminalId', 'agentName'] as $key) {
            if ($agent->{$key} !== $this->string($session, $key)) {
                throw new LogicException('The assigned Herdr session changed; no replacement will be prompted.');
            }
        }
        if ($agent->workingDirectory !== $workspace->worktree
            || (($session['agentId'] ?? null) !== null && $agent->agentId !== $session['agentId'])) {
            throw new LogicException('The assigned Herdr conversation or checkout changed.');
        }
    }

    /** @param array<string, mixed> $values */
    private function string(array $values, string $key): string
    {
        $value = $values[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new LogicException('Runtime identity/configuration is missing '.$key.'.');
        }

        return $value;
    }
}
