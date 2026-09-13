<?php

declare(strict_types=1);

namespace App\Operations;

use App\Herdr\Payload;
use App\Herdr\SocketClient;
use Throwable;

final class HerdrFleetSnapshot
{
    /** @return array{sessions: list<array<string, mixed>>, fetched_at: string} */
    public function get(): array
    {
        $sessions = [];
        $configured = config('commander.herdr.sessions');
        $configured = is_array($configured) ? $configured : [];

        foreach ($configured as $session) {
            if (! is_array($session)) {
                continue;
            }

            $socket = self::string($session['socket'] ?? null);
            $node = self::string($session['node'] ?? null, 'unknown');
            $name = self::string($session['name'] ?? null, 'unknown');

            try {
                $client = new SocketClient($socket, 1.0);
                $agents = Payload::list($client->request('agent.list')['agents'] ?? null);
                $workspaces = Payload::list($client->request('workspace.list')['workspaces'] ?? null);

                $sessions[] = [
                    'node' => $node,
                    'name' => $name,
                    'status' => 'online',
                    'agents' => array_map(static fn (mixed $agent): array => is_array($agent) ? [
                        'name' => self::string($agent['name'] ?? null, self::string($agent['display_agent'] ?? null, self::string($agent['pane_id'] ?? null, 'Agent'))),
                        'kind' => self::string($agent['agent'] ?? null, 'unknown'),
                        'status' => self::string($agent['agent_status'] ?? null, 'unknown'),
                        'workspace_id' => self::string($agent['workspace_id'] ?? null),
                    ] : [], $agents),
                    'workspaces' => array_map(static fn (mixed $workspace): array => is_array($workspace) ? [
                        'id' => self::string($workspace['workspace_id'] ?? null),
                        'label' => self::string($workspace['label'] ?? null, self::string($workspace['workspace_id'] ?? null, 'Workspace')),
                    ] : [], $workspaces),
                ];
            } catch (Throwable) {
                $sessions[] = [
                    'node' => $node,
                    'name' => $name,
                    'status' => 'unavailable',
                    'agents' => [],
                    'workspaces' => [],
                ];
            }
        }

        return ['sessions' => $sessions, 'fetched_at' => now()->toIso8601String()];
    }

    private static function string(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }
}
