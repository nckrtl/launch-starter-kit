<?php

declare(strict_types=1);

namespace App\Tasks\Runtime;

use App\Tasks\GitObjectId;
use InvalidArgumentException;

/** @phpstan-type CheckpointRequest array{reason:string,evidence:string,prerequisite:array{source:string,pull_request:string,candidate:string,merge:string,main:string,review:array{path:string,sha256:string},verification:array{head:string,command:list<string>,working_directory:string,exit_code:0,log:array{path:string,sha256:string}}},integration?:'preserve_history'} */
final class TaskReattemptInput
{
    /** @return array<string, mixed> */
    public function read(string $file, string $worktree): array
    {
        $this->file($file, $worktree, 262_144);
        $value = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('The request must be a JSON object.');
        }
        foreach (array_keys($value) as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Use named reattempt request fields.');
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @param array<string, mixed> $request
     * @return CheckpointRequest
     */
    public function checkpoint(array $request, string $worktree): array
    {
        $integration = array_key_exists('integration', $request);
        $this->keys($request, ['reason', 'evidence', 'prerequisite', ...($integration ? ['integration'] : [])]);
        if ($integration && $request['integration'] !== 'preserve_history') {
            throw new InvalidArgumentException('The only optional integration strategy is preserve_history.');
        }
        $prerequisite = $request['prerequisite'] ?? null;
        if (! is_array($prerequisite)) {
            throw new InvalidArgumentException('A separately reviewed, merged, verified prerequisite is required.');
        }
        $this->keys($prerequisite, ['source', 'pull_request', 'candidate', 'merge', 'main', 'review', 'verification']);
        $normalized = ['source' => $this->text($prerequisite, 'source', 255), 'pull_request' => $this->text($prerequisite, 'pull_request', 2000)];
        if (! str_starts_with($normalized['pull_request'], 'https://') || filter_var($normalized['pull_request'], FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Pin the authoritative prerequisite pull request URL.');
        }
        foreach (['candidate', 'merge', 'main'] as $key) {
            $normalized[$key] = $this->text($prerequisite, $key, 64);
            GitObjectId::validate($normalized[$key]);
        }
        $normalized['review'] = $this->evidence($prerequisite['review'] ?? null, $worktree);
        $verification = $prerequisite['verification'] ?? null;
        if (! is_array($verification)) {
            throw new InvalidArgumentException('Retain exact successful main verification.');
        }
        $this->keys($verification, ['head', 'command', 'working_directory', 'exit_code', 'log']);
        $command = $verification['command'] ?? null;
        if (($verification['head'] ?? null) !== $normalized['main'] || ($verification['exit_code'] ?? null) !== 0
            || ! is_array($command) || ! array_is_list($command) || $command === [] || count($command) > 64) {
            throw new InvalidArgumentException('Main verification must pass at the exact pinned main commit.');
        }
        foreach ($command as $argument) {
            if (! is_string($argument) || $argument === '' || mb_strlen($argument) > 4096 || str_contains($argument, "\0")) {
                throw new InvalidArgumentException('Verification commands require nonempty string arguments.');
            }
        }
        $normalized['verification'] = ['head' => $normalized['main'], 'command' => $command,
            'working_directory' => $this->text($verification, 'working_directory', 4096), 'exit_code' => 0,
            'log' => $this->evidence($verification['log'] ?? null, $worktree)];

        return ['reason' => $this->text($request, 'reason'), 'evidence' => $this->text($request, 'evidence'), 'prerequisite' => $normalized,
            ...($integration ? ['integration' => 'preserve_history'] : [])];
    }

    /** @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function restoration(array $request, string $worktree): array
    {
        $this->keys($request, ['reason', 'evidence', 'log']);

        return ['reason' => $this->text($request, 'reason'), 'evidence' => $this->text($request, 'evidence'),
            'log' => $this->evidence($request['log'] ?? null, $worktree)];
    }

    public function hash(mixed $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }

    public function pin(string $value): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1) {
            throw new InvalidArgumentException('An exact SHA-256 state or manifest pin is required.');
        }
    }

    /** @return array{path:string,sha256:string} */
    private function evidence(mixed $value, string $worktree): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('Retained evidence requires its path and SHA-256.');
        }
        $this->keys($value, ['path', 'sha256']);
        $path = $this->text($value, 'path', 4096);
        $hash = $this->text($value, 'sha256', 64);
        $this->pin($hash);
        $this->file($path, $worktree, 10_485_760);
        if (! hash_equals($hash, (string) hash_file('sha256', $path))) {
            throw new InvalidArgumentException('Retained evidence changed from its approved hash.');
        }

        return ['path' => $path, 'sha256' => $hash];
    }

    private function file(string $path, string $worktree, int $limit): void
    {
        if (! str_starts_with($path, '/') || realpath($path) !== $path || ! is_file($path) || ! is_readable($path)
            || str_starts_with($path, $worktree.'/') || filesize($path) > $limit) {
            throw new InvalidArgumentException('Use a bounded readable canonical evidence file outside the worktree.');
        }
    }

    /** @param array<array-key, mixed> $value
     * @param  list<string>  $keys
     */
    private function keys(array $value, array $keys): void
    {
        if (array_diff(array_keys($value), $keys) !== [] || array_diff($keys, array_keys($value)) !== []) {
            throw new InvalidArgumentException('Use exactly the documented reattempt request fields.');
        }
    }

    /** @param array<array-key, mixed> $value */
    private function text(array $value, string $key, int $limit = 100_000): string
    {
        $text = $value[$key] ?? null;
        if (! is_string($text) || trim($text) === '' || mb_strlen($text) > $limit) {
            throw new InvalidArgumentException('A bounded nonempty '.$key.' is required.');
        }

        return $text;
    }
}
