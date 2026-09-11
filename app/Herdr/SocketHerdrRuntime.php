<?php

declare(strict_types=1);

namespace App\Herdr;

use App\Delivery\Contracts\HerdrRuntime;
use App\Delivery\Data\HerdrAgentIdentifiers;
use App\Delivery\Data\HerdrAgentLaunch;
use App\Delivery\Data\OpenedHerdrWorktree;
use InvalidArgumentException;
use Throwable;

final readonly class SocketHerdrRuntime implements HerdrRuntime
{
    private const int PROMPT_READY_ATTEMPTS = 41;

    private const int PROMPT_RETRY_DELAY_MILLISECONDS = 250;

    public function __construct(private SocketClient $client) {}

    public function openWorktree(
        string $repositoryPath,
        string $worktreePath,
        ?string $label = null,
    ): OpenedHerdrWorktree {
        $parameters = [
            'cwd' => $repositoryPath,
            'path' => $worktreePath,
            'focus' => false,
            'trust_repository' => false,
        ];

        if ($label !== null) {
            $parameters['label'] = $label;
        }

        $result = $this->client->request('worktree.open', $parameters);
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

    public function startAgent(
        string $paneId,
        string $name,
        ?HerdrAgentLaunch $launch = null,
    ): HerdrAgentIdentifiers {
        $launch ??= HerdrAgentLaunch::default();
        $result = $this->client->request('agent.start', [
            'pane_id' => $paneId,
            'name' => $name,
            'kind' => $launch->kind,
            'args' => $launch->arguments,
            'timeout_ms' => $launch->timeoutMilliseconds,
        ]);
        $this->assertType($result, 'agent_started');

        return $this->identifiers(Payload::assoc($result['agent'] ?? null), $name);
    }

    public function promptAgent(string $name, string $prompt): HerdrAgentIdentifiers
    {
        $result = retry(
            self::PROMPT_READY_ATTEMPTS,
            fn (int $_attempt): array => $this->client->request('agent.prompt', ['target' => $name, 'text' => $prompt]),
            self::PROMPT_RETRY_DELAY_MILLISECONDS,
            static fn (Throwable $exception): bool => $exception instanceof RequestFailed
                && $exception->errorCode === 'agent_not_ready',
        );

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
