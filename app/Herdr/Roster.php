<?php

namespace App\Herdr;

/**
 * Snapshot of the session's agent panes and workspace labels.
 */
final readonly class Roster
{
    /**
     * @param  array<string, array{workspace_id: string, agent: string|null, name: string|null, status: string}>  $panes
     * @param  array<string, string>  $workspaceLabels
     */
    private function __construct(
        private array $panes,
        private array $workspaceLabels,
    ) {}

    public static function fetch(SocketClient $client): self
    {
        $agents = Payload::list($client->request('agent.list')['agents'] ?? null);
        $workspaces = Payload::list($client->request('workspace.list')['workspaces'] ?? null);

        return self::fromPayloads($agents, $workspaces);
    }

    /**
     * @param  list<mixed>  $agents
     * @param  list<mixed>  $workspaces
     */
    public static function fromPayloads(array $agents, array $workspaces): self
    {
        $panes = [];

        foreach ($agents as $agent) {
            $agent = Payload::assoc($agent);
            $paneId = Payload::string($agent['pane_id'] ?? null);
            $workspaceId = Payload::string($agent['workspace_id'] ?? null);

            if ($paneId === null || $workspaceId === null) {
                continue;
            }

            $panes[$paneId] = [
                'workspace_id' => $workspaceId,
                'agent' => Payload::string($agent['agent'] ?? null),
                'name' => Payload::string($agent['name'] ?? null) ?? Payload::string($agent['display_agent'] ?? null),
                'status' => Payload::string($agent['agent_status'] ?? null) ?? 'unknown',
            ];
        }

        $labels = [];

        foreach ($workspaces as $workspace) {
            $workspace = Payload::assoc($workspace);
            $workspaceId = Payload::string($workspace['workspace_id'] ?? null);
            $label = Payload::string($workspace['label'] ?? null);

            if ($workspaceId !== null && $label !== null) {
                $labels[$workspaceId] = $label;
            }
        }

        return new self($panes, $labels);
    }

    /**
     * @return list<string>
     */
    public function paneIds(): array
    {
        return array_keys($this->panes);
    }

    /**
     * @return array<string, string>
     */
    public function statuses(): array
    {
        return array_map(fn (array $pane): string => $pane['status'], $this->panes);
    }

    public function agentKind(string $paneId): ?string
    {
        return $this->panes[$paneId]['agent'] ?? null;
    }

    public function agentName(string $paneId): ?string
    {
        return $this->panes[$paneId]['name'] ?? null;
    }

    public function workspaceId(string $paneId): ?string
    {
        return $this->panes[$paneId]['workspace_id'] ?? null;
    }

    public function workspaceLabel(string $workspaceId): ?string
    {
        return $this->workspaceLabels[$workspaceId] ?? null;
    }

    public function describe(string $paneId): string
    {
        $workspaceId = $this->workspaceId($paneId);
        $workspace = $workspaceId === null ? null : ($this->workspaceLabel($workspaceId) ?? $workspaceId);

        return sprintf('%s (%s, %s)', $this->agentName($paneId) ?? $paneId, $workspace ?? '-', $paneId);
    }
}
