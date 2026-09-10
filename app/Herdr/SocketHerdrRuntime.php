<?php

declare(strict_types=1);

namespace App\Herdr;

use App\Delivery\Contracts\HerdrRuntime;
use App\Delivery\Data\HerdrAgentIdentifiers;
use App\Delivery\Data\OpenedHerdrWorktree;
use InvalidArgumentException;

final readonly class SocketHerdrRuntime implements HerdrRuntime
{
    public function __construct(private SocketClient $client) {}

    public function openWorktree(string $path): OpenedHerdrWorktree
    {
        $result = $this->client->request('worktree.open', [
            'path' => $path,
            'focus' => false,
            'trust_repository' => false,
        ]);
        $this->assertType($result, 'worktree_opened');
        $workspace = Payload::assoc($result['workspace'] ?? null);
        $tab = Payload::assoc($result['tab'] ?? null);
        $pane = Payload::assoc($result['root_pane'] ?? null);

        return new OpenedHerdrWorktree(
            workspaceId: $this->requiredString($workspace, 'workspace_id'),
            tabId: $this->requiredString($tab, 'tab_id'),
            paneId: $this->requiredString($pane, 'pane_id'),
            terminalId: $this->requiredString($pane, 'terminal_id'),
            alreadyOpen: ($result['already_open'] ?? null) === true,
        );
    }

    public function splitPane(string $paneId, string $workingDirectory): HerdrAgentIdentifiers
    {
        $result = $this->client->request('pane.split', [
            'target_pane_id' => $paneId,
            'direction' => 'right',
            'cwd' => $workingDirectory,
            'focus' => false,
        ]);
        $this->assertType($result, 'pane_info');

        return $this->identifiers(Payload::assoc($result['pane'] ?? null), '');
    }

    public function startAgent(string $paneId, string $name): HerdrAgentIdentifiers
    {
        $result = $this->client->request('agent.start', [
            'pane_id' => $paneId,
            'name' => $name,
            'kind' => 'codex',
            'args' => [],
            'timeout_ms' => 15_000,
        ]);
        $this->assertType($result, 'agent_started');

        return $this->identifiers(Payload::assoc($result['agent'] ?? null), $name);
    }

    public function promptAgent(string $name, string $prompt): HerdrAgentIdentifiers
    {
        $result = $this->client->request('agent.prompt', ['target' => $name, 'text' => $prompt]);
        $this->assertType($result, 'agent_prompted');

        return $this->identifiers(Payload::assoc($result['agent'] ?? null), $name);
    }

    public function getAgent(string $name): HerdrAgentIdentifiers
    {
        $result = $this->client->request('agent.get', ['target' => $name]);
        $this->assertType($result, 'agent_info');

        return $this->identifiers(Payload::assoc($result['agent'] ?? null), $name);
    }

    /** @param array<string, mixed> $agent */
    private function identifiers(array $agent, string $fallbackName): HerdrAgentIdentifiers
    {
        return new HerdrAgentIdentifiers(
            workspaceId: $this->requiredString($agent, 'workspace_id'),
            tabId: $this->requiredString($agent, 'tab_id'),
            paneId: $this->requiredString($agent, 'pane_id'),
            terminalId: $this->requiredString($agent, 'terminal_id'),
            agentId: Payload::string($agent['agent'] ?? null),
            agentName: Payload::string($agent['name'] ?? null) ?? $fallbackName,
            stateChangeSeq: is_int($agent['state_change_seq'] ?? null) ? $agent['state_change_seq'] : null,
        );
    }

    /** @param array<string, mixed> $result */
    private function assertType(array $result, string $expected): void
    {
        if (($result['type'] ?? null) !== $expected) {
            throw new InvalidArgumentException("Herdr returned an invalid response; expected [{$expected}].");
        }
    }

    /** @param array<string, mixed> $payload */
    private function requiredString(array $payload, string $key): string
    {
        return Payload::string($payload[$key] ?? null)
            ?? throw new InvalidArgumentException("Herdr response is missing [{$key}].");
    }
}
