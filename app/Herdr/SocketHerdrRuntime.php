<?php

declare(strict_types=1);

namespace App\Herdr;

use App\Delivery\Contracts\HerdrRuntime;
use App\Delivery\Contracts\HerdrWorkspaceRuntime;
use App\Delivery\Data\HerdrAgentIdentifiers;
use App\Delivery\Data\HerdrAgentLaunch;
use App\Delivery\Data\HerdrAgentOutput;
use App\Delivery\Data\HerdrForegroundProcess;
use App\Delivery\Data\HerdrPaneProcessInfo;
use App\Delivery\Data\HerdrSessionSnapshot;
use App\Delivery\Data\HerdrSnapshotAgent;
use App\Delivery\Data\HerdrSnapshotPane;
use App\Delivery\Data\HerdrSnapshotWorkspace;
use App\Delivery\Data\OpenedHerdrWorktree;
use InvalidArgumentException;
use Throwable;

final readonly class SocketHerdrRuntime implements HerdrRuntime, HerdrWorkspaceRuntime
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

    public function createTab(
        string $workspaceId,
        string $workingDirectory,
        ?string $label = null,
    ): HerdrAgentIdentifiers {
        $parameters = [
            'workspace_id' => $workspaceId,
            'cwd' => $workingDirectory,
            'focus' => false,
        ];

        if ($label !== null) {
            $parameters['label'] = $label;
        }

        $result = $this->client->request('tab.create', $parameters);
        $this->assertType($result, 'tab_created');

        return $this->identifiers(Payload::assoc($result['root_pane'] ?? null), '');
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

    public function promptAgentOnce(string $name, string $prompt): HerdrAgentIdentifiers
    {
        $result = $this->client->request('agent.prompt', ['target' => $name, 'text' => $prompt]);
        $this->assertType($result, 'agent_prompted');

        return $this->identifiers(Payload::assoc($result['agent'] ?? null), $name);
    }

    public function snapshot(): HerdrSessionSnapshot
    {
        $result = $this->client->request('session.snapshot');
        $this->assertType($result, 'session_snapshot');
        $snapshot = $this->requiredMap($result, 'snapshot');
        $this->requiredList($snapshot, 'tabs');
        $this->requiredList($snapshot, 'layouts');
        $workspaces = array_map(
            fn (mixed $workspace): HerdrSnapshotWorkspace => $this->snapshotWorkspace($workspace),
            $this->requiredList($snapshot, 'workspaces'),
        );
        $panes = array_map(
            fn (mixed $pane): HerdrSnapshotPane => $this->snapshotPane($pane),
            $this->requiredList($snapshot, 'panes'),
        );
        $agents = array_map(
            fn (mixed $agent): HerdrSnapshotAgent => $this->snapshotAgent($agent),
            $this->requiredList($snapshot, 'agents'),
        );

        return new HerdrSessionSnapshot(
            version: $this->requiredString($snapshot, 'version'),
            protocol: $this->requiredInteger($snapshot, 'protocol'),
            workspaces: $workspaces,
            panes: $panes,
            agents: $agents,
        );
    }

    public function readAgent(string $name): HerdrAgentOutput
    {
        $result = $this->client->request('agent.read', [
            'target' => $name,
            'source' => 'recent_unwrapped',
            'format' => 'text',
            'lines' => 160,
            'strip_ansi' => true,
        ]);
        $this->assertType($result, 'pane_read');
        $read = $this->requiredMap($result, 'read');

        if (($read['source'] ?? null) !== 'recent_unwrapped'
            || ($read['format'] ?? null) !== 'text') {
            throw new InvalidArgumentException('Herdr returned an invalid agent output source.');
        }

        return new HerdrAgentOutput(
            workspaceId: $this->requiredString($read, 'workspace_id'),
            tabId: $this->requiredString($read, 'tab_id'),
            paneId: $this->requiredString($read, 'pane_id'),
            text: $this->string($read, 'text'),
        );
    }

    public function sendAgentKeys(string $name, array $keys): void
    {
        if ($name === '' || $keys === [] || in_array('', $keys, true)) {
            throw new InvalidArgumentException('Herdr agent keys must be non-empty strings.');
        }

        $result = $this->client->request('agent.send_keys', [
            'target' => $name,
            'keys' => $keys,
        ]);
        $this->assertType($result, 'ok');
    }

    public function inspectPaneProcess(string $paneId): HerdrPaneProcessInfo
    {
        $result = $this->client->request('pane.process_info', ['pane_id' => $paneId]);
        $this->assertType($result, 'pane_process_info');
        $info = $this->requiredMap($result, 'process_info');
        $processes = array_map(function (mixed $process): HerdrForegroundProcess {
            if (! is_array($process) || array_is_list($process)) {
                throw new InvalidArgumentException('Herdr returned an invalid foreground process.');
            }

            $process = Payload::assoc($process);

            return new HerdrForegroundProcess(
                processId: $this->requiredInteger($process, 'pid'),
                name: $this->requiredString($process, 'name'),
            );
        }, $this->requiredList($info, 'foreground_processes'));

        return new HerdrPaneProcessInfo(
            paneId: $this->requiredString($info, 'pane_id'),
            shellProcessId: $this->nullableInteger($info, 'shell_pid'),
            foregroundProcessGroupId: $this->nullableInteger($info, 'foreground_process_group_id'),
            foregroundProcesses: $processes,
        );
    }

    public function closeWorkspace(string $workspaceId, int $protocol): void
    {
        if ($protocol < 20) {
            throw new InvalidArgumentException('Herdr workspace close requires protocol 20 or newer.');
        }

        $parameters = ['workspace_id' => $workspaceId];

        if ($protocol >= 22) {
            $parameters['close_group'] = false;
        }

        $result = $this->client->request('workspace.close', $parameters);
        $this->assertType($result, 'ok');
    }

    /** @param array<string, mixed> $agent */
    private function identifiers(array $agent, string $fallbackName): HerdrAgentIdentifiers
    {
        return new HerdrAgentIdentifiers(
            workspaceId: $this->requiredString($agent, 'workspace_id'),
            tabId: $this->requiredString($agent, 'tab_id'),
            paneId: $this->requiredString($agent, 'pane_id'),
            terminalId: $this->requiredString($agent, 'terminal_id'),
            agentId: $this->agentSessionId($agent),
            agentName: Payload::string($agent['name'] ?? null) ?? $fallbackName,
            stateChangeSeq: is_int($agent['state_change_seq'] ?? null) ? $agent['state_change_seq'] : null,
            workingDirectory: Payload::string($agent['cwd'] ?? null),
            agentStatus: Payload::string($agent['agent_status'] ?? null),
        );
    }

    /** @param array<string, mixed> $agent */
    private function agentSessionId(array $agent): ?string
    {
        $session = $agent['agent_session'] ?? null;

        if ($session === null) {
            return null;
        }

        if (! is_array($session) || array_is_list($session)) {
            throw new InvalidArgumentException('Herdr returned an invalid agent session identity.');
        }

        return $this->requiredString(Payload::assoc($session), 'value');
    }

    private function snapshotWorkspace(mixed $value): HerdrSnapshotWorkspace
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('Herdr returned an invalid workspace snapshot.');
        }

        $value = Payload::assoc($value);

        $worktree = $value['worktree'] ?? null;

        if ($worktree !== null && (! is_array($worktree) || array_is_list($worktree))) {
            throw new InvalidArgumentException('Herdr returned invalid workspace worktree provenance.');
        }

        $worktree = is_array($worktree) ? Payload::assoc($worktree) : null;

        return new HerdrSnapshotWorkspace(
            workspaceId: $this->requiredString($value, 'workspace_id'),
            repositoryRoot: is_array($worktree) ? $this->requiredString($worktree, 'repo_root') : null,
            checkoutPath: is_array($worktree) ? $this->requiredString($worktree, 'checkout_path') : null,
            linkedWorktree: is_array($worktree) ? $this->requiredBoolean($worktree, 'is_linked_worktree') : null,
        );
    }

    private function snapshotPane(mixed $value): HerdrSnapshotPane
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('Herdr returned an invalid pane snapshot.');
        }

        $value = Payload::assoc($value);

        return new HerdrSnapshotPane(
            workspaceId: $this->requiredString($value, 'workspace_id'),
            tabId: $this->requiredString($value, 'tab_id'),
            paneId: $this->requiredString($value, 'pane_id'),
            terminalId: $this->requiredString($value, 'terminal_id'),
            workingDirectory: $this->nullableString($value, 'cwd'),
        );
    }

    private function snapshotAgent(mixed $value): HerdrSnapshotAgent
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('Herdr returned an invalid agent snapshot.');
        }

        $value = Payload::assoc($value);

        $status = $this->requiredString($value, 'agent_status');

        if (! in_array($status, ['idle', 'working', 'blocked', 'done', 'unknown'], true)) {
            throw new InvalidArgumentException('Herdr returned an unknown agent status.');
        }

        return new HerdrSnapshotAgent(
            workspaceId: $this->requiredString($value, 'workspace_id'),
            tabId: $this->requiredString($value, 'tab_id'),
            paneId: $this->requiredString($value, 'pane_id'),
            terminalId: $this->requiredString($value, 'terminal_id'),
            kind: $this->nullableString($value, 'agent'),
            name: $this->nullableString($value, 'name'),
            status: $status,
            workingDirectory: $this->nullableString($value, 'cwd'),
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

    /** @param array<string, mixed> $payload */
    private function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value)) {
            throw new InvalidArgumentException("Herdr response is missing [{$key}].");
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function nullableString(array $payload, string $key): ?string
    {
        if (! array_key_exists($key, $payload) || $payload[$key] === null) {
            return null;
        }

        return $this->requiredString($payload, $key);
    }

    /** @param array<string, mixed> $payload */
    private function requiredInteger(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;

        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException("Herdr response is missing [{$key}].");
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function nullableInteger(array $payload, string $key): ?int
    {
        if (! array_key_exists($key, $payload) || $payload[$key] === null) {
            return null;
        }

        return $this->requiredInteger($payload, $key);
    }

    /** @param array<string, mixed> $payload */
    private function requiredBoolean(array $payload, string $key): bool
    {
        $value = $payload[$key] ?? null;

        if (! is_bool($value)) {
            throw new InvalidArgumentException("Herdr response is missing [{$key}].");
        }

        return $value;
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function requiredMap(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;

        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException("Herdr response is missing [{$key}].");
        }

        return Payload::assoc($value);
    }

    /** @param array<string, mixed> $payload
     * @return list<mixed>
     */
    private function requiredList(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;

        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("Herdr response is missing [{$key}].");
        }

        return $value;
    }
}
