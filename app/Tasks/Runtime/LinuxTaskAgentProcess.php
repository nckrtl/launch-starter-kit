<?php

declare(strict_types=1);

namespace App\Tasks\Runtime;

use LogicException;

final readonly class LinuxTaskAgentProcess
{
    public function __construct(private string $proc = '/proc') {}

    /** @return array<string, mixed> */
    public function read(int $pid): array
    {
        if ($pid < 1) {
            throw new LogicException('A live positive process ID is required.');
        }
        $path = $this->proc.'/'.$pid;
        $stat = $this->contents($path.'/stat');
        $end = strrpos($stat, ')');
        $fields = $end === false ? [] : preg_split('/\s+/', trim(substr($stat, $end + 1)));
        $status = $this->contents($path.'/status');
        if (! is_array($fields) || count($fields) < 20 || in_array($fields[0], ['Z', 'X'], true)
            || preg_match('/^Uid:\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)$/m', $status, $uids) !== 1
            || count(array_unique(array_slice($uids, 1))) !== 1 || (int) $uids[1] !== posix_geteuid()) {
            throw new LogicException('The task process is missing, exited, or owned by another user.');
        }
        $argv = explode("\0", rtrim($this->contents($path.'/cmdline'), "\0"));
        $cwd = @readlink($path.'/cwd');
        $exe = @readlink($path.'/exe');
        $terminal = @readlink($path.'/fd/0');
        if (! is_string($cwd) || ! is_string($exe) || ! is_string($terminal) || ! str_starts_with($terminal, '/dev/pts/')
            || (int) $fields[4] === 0 || ! ctype_digit($fields[19])) {
            throw new LogicException('The live process has no verifiable checkout, executable or PTY.');
        }
        $result = ['pid' => $pid, 'start_time' => $fields[19], 'uid' => (int) $uids[1], 'tty' => (int) $fields[4],
            'session_id' => (int) $fields[3], 'process_group' => (int) $fields[2], 'foreground_group' => (int) $fields[5],
            'terminal' => $terminal, 'argv' => $argv, 'cwd' => $cwd, 'executable' => $exe,
            'boot_id' => trim($this->contents($this->proc.'/sys/kernel/random/boot_id'))];
        if ($this->contents($path.'/stat') !== $stat) {
            // CPU accounting can change; process start identity cannot.
            $current = $this->contents($path.'/stat');
            $currentEnd = strrpos($current, ')');
            $currentFields = $currentEnd === false ? [] : preg_split('/\s+/', trim(substr($current, $currentEnd + 1)));
            if (! is_array($currentFields) || ($currentFields[19] ?? null) !== $result['start_time']) {
                throw new LogicException('The process identity changed during inspection.');
            }
        }

        return $result;
    }

    private function contents(string $path): string
    {
        $contents = @file_get_contents($path);
        if ($contents === false || strlen($contents) > 262_144) {
            throw new LogicException('Cannot read bounded live task process evidence.');
        }

        return $contents;
    }
}
