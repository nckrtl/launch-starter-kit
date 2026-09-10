<?php

declare(strict_types=1);

namespace App\Operations;

use App\Projects\ProjectDetails;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

final class HermesKanbanSnapshot
{
    /** @return array<string, mixed> */
    public function get(): array
    {
        return Cache::remember('commander:hermes:kanban:v1', 15, function (): array {
            $snapshot = $this->fetch();
            if ($snapshot['status'] === 'online') {
                Cache::put('commander:hermes:kanban:latest:v1', $snapshot, now()->addDays(7));
            } elseif (in_array($snapshot['status'], ['unavailable', 'partial'], true) && ($saved = $this->cached()) !== null) {
                return [...$saved, 'refresh_failed' => true];
            }

            return $snapshot;
        });
    }

    /** @return array<string, mixed>|null */
    public function cached(): ?array
    {
        $snapshot = Cache::get('commander:hermes:kanban:latest:v1');
        if (! is_array($snapshot)) {
            return null;
        }

        return ProjectDetails::rows([$snapshot])[0] ?? null;
    }

    /** @return array<string, mixed> */
    private function fetch(): array
    {
        $boards = [];
        $cards = [];
        $unavailable = [];
        try {
            $result = Process::timeout(8)->run($this->command('boards list --json'));
            $result->throw();
            foreach ($this->rows($result->output()) as $board) {
                $slug = ProjectDetails::text($board['slug'] ?? null);
                if (! preg_match('/^[a-zA-Z0-9_-]+$/D', $slug)) {
                    throw new RuntimeException('Invalid Hermes board identity.');
                }
                $boards[] = ['slug' => $slug, 'name' => ProjectDetails::text($board['name'] ?? null, $slug)];
            }
            foreach (array_chunk($boards, 6) as $batch) {
                $results = Process::pool(function (Pool $pool) use ($batch): void {
                    foreach ($batch as $board) {
                        $pool->as($board['slug'])->timeout(8)->command($this->command('--board '.escapeshellarg($board['slug']).' list --archived --json'));
                    }
                })->run();
                foreach ($batch as $board) {
                    try {
                        $result = $results->collect()->get($board['slug']);
                        if (! $result instanceof ProcessResult) {
                            throw new RuntimeException('Missing Hermes result.');
                        }
                        $result->throw();
                        foreach ($this->rows($result->output()) as $card) {
                            $cards[] = [
                                'id' => ProjectDetails::text($card['id'] ?? null),
                                'title' => ProjectDetails::text($card['title'] ?? null, 'Untitled card'),
                                'status' => ProjectDetails::text($card['status'] ?? null, 'unknown'),
                                'assignee' => strtolower(ProjectDetails::text($card['assignee'] ?? null)),
                                'project' => $board['slug'], 'project_name' => $board['name'],
                            ];
                        }
                    } catch (Throwable $exception) {
                        report($exception);
                        $unavailable[] = $board['name'];
                    }
                }
            }
        } catch (Throwable $exception) {
            report($exception);

            return ['status' => 'unavailable', 'boards' => [], 'cards' => [], 'unavailable' => [], 'fetched_at' => now()->toIso8601String()];
        }

        return ['status' => $unavailable === [] ? 'online' : 'partial', 'boards' => $boards, 'cards' => $cards, 'unavailable' => $unavailable, 'fetched_at' => now()->toIso8601String()];
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $json): array
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new RuntimeException('Invalid Hermes response.');
        }

        return ProjectDetails::rows($decoded);
    }

    /** @return list<string> */
    private function command(string $arguments): array
    {
        $target = ProjectDetails::text(config('commander.hermes.ssh_target'));
        $binary = ProjectDetails::text(config('commander.hermes.binary'));
        if ($target === '' || $binary === '') {
            throw new RuntimeException('Hermes is not configured.');
        }

        return ['ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=3', $target, escapeshellarg($binary).' kanban '.$arguments];
    }
}
