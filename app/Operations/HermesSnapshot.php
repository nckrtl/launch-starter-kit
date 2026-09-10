<?php

declare(strict_types=1);

namespace App\Operations;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Throwable;

final class HermesSnapshot
{
    /** @return array<string, mixed> */
    public function get(): array
    {
        $seconds = config('commander.hermes.cache_seconds');

        $fresh = is_int($seconds) ? $seconds : 15;

        return Cache::flexible('commander:hermes:snapshot', [$fresh, $fresh * 4], fn (): array => $this->fetch());
    }

    /** @return array<string, mixed> */
    private function fetch(): array
    {
        try {
            $profiles = [];

            $configuredProfiles = config('commander.hermes.profiles');
            $configuredProfiles = is_array($configuredProfiles) ? $configuredProfiles : [];

            foreach ($configuredProfiles as $name => $home) {
                if (! is_string($name) || ! is_string($home)) {
                    continue;
                }

                $state = $this->remoteJson("cat {$home}/gateway_state.json");
                $sessions = $this->remoteJson("cat {$home}/runtime/active_sessions.json");
                $entries = array_values(is_array($sessions['entries'] ?? null) ? $sessions['entries'] : []);

                $profiles[] = [
                    'name' => ucfirst($name),
                    'state' => self::string($state['gateway_state'] ?? null, 'unknown'),
                    'busy' => count($entries) > 0 || self::integer($state['active_agents'] ?? null) > 0,
                    'active_sessions' => array_map(static fn (mixed $entry): array => is_array($entry) ? [
                        'session_id' => self::string($entry['session_id'] ?? null),
                        'surface' => self::string($entry['surface'] ?? null, 'unknown'),
                        'started_at' => $entry['started_at'] ?? null,
                        'updated_at' => $entry['updated_at'] ?? null,
                    ] : [], $entries),
                    'updated_at' => $state['updated_at'] ?? null,
                    'version' => $state['code_version'] ?? null,
                ];
            }

            $binary = config('commander.hermes.binary');

            if (! is_string($binary) || $binary === '') {
                throw new \RuntimeException('Hermes binary is not configured.');
            }

            $boards = $this->remoteJson($binary.' kanban boards list --json', list: true);
            $signals = $this->remoteJson($binary.' kanban --board orbit diagnostics --json', list: true);

            return [
                'status' => 'online',
                'node' => 'mini',
                'fetched_at' => now()->toIso8601String(),
                'profiles' => $profiles,
                'boards' => array_map(static fn (mixed $board): array => is_array($board) ? [
                    'slug' => self::string($board['slug'] ?? null),
                    'name' => self::string($board['name'] ?? null, self::string($board['slug'] ?? null)),
                    'description' => self::string($board['description'] ?? null),
                    'counts' => is_array($board['counts'] ?? null) ? $board['counts'] : [],
                    'total' => self::integer($board['total'] ?? null),
                ] : [], $boards),
                'signals' => array_map(static fn (mixed $signal): array => is_array($signal) ? [
                    'kind' => self::string($signal['kind'] ?? null, self::string($signal['type'] ?? null, 'signal')),
                    'title' => self::string($signal['title'] ?? null, self::string($signal['message'] ?? null, 'Kanban signal')),
                    'severity' => self::string($signal['severity'] ?? null, 'info'),
                ] : [], $signals),
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'status' => 'unavailable',
                'node' => 'mini',
                'fetched_at' => now()->toIso8601String(),
                'profiles' => [],
                'boards' => [],
                'signals' => [],
                'error' => 'Hermes on Mini could not be reached.',
            ];
        }
    }

    /** @return array<string, mixed>|list<mixed> */
    private function remoteJson(string $command, bool $list = false): array
    {
        $timeout = config('commander.hermes.timeout');
        $target = config('commander.hermes.ssh_target');

        if (! is_string($target) || $target === '') {
            throw new \RuntimeException('Hermes SSH target is not configured.');
        }

        $result = Process::timeout(is_int($timeout) ? $timeout : 5)
            ->run(['ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=3', $target, $command]);

        $result->throw();
        $decoded = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            return [];
        }

        return $list ? array_values($decoded) : $decoded;
    }

    private static function string(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }

    private static function integer(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }
}
