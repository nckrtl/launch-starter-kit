<?php

declare(strict_types=1);

namespace App\Tasks\Landing;

use InvalidArgumentException;

final class TaskLandingData
{
    /** @return array<string, mixed> */
    public static function object(mixed $value): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))
            || array_any(array_keys($value), fn (mixed $key): bool => ! is_string($key))) {
            throw new InvalidArgumentException('Expected a named landing object.');
        }

        return $value;
    }

    /** @param array<string, mixed> $value */
    public static function text(array $value, string $key, int $limit = 100_000): string
    {
        $text = $value[$key] ?? null;
        if (! is_string($text) || trim($text) === '' || strlen($text) > $limit || str_contains($text, "\0")) {
            throw new InvalidArgumentException('Missing or invalid landing '.$key.'.');
        }

        return $text;
    }

    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', self::json($value));
    }

    public static function file(string $path, int $limit = 1_048_576): string
    {
        if (! str_starts_with($path, '/') || realpath($path) !== $path || is_link($path) || ! is_file($path)
            || ! is_readable($path) || filesize($path) > $limit) {
            throw new InvalidArgumentException('Landing evidence requires a bounded canonical regular file.');
        }
        $contents = file_get_contents($path);
        if ($contents === false || strlen($contents) > $limit || ! mb_check_encoding($contents, 'UTF-8') || str_contains($contents, "\0")) {
            throw new InvalidArgumentException('Landing evidence must be bounded UTF-8 text.');
        }

        return $contents;
    }

    /** @return array<string, mixed> */
    public static function readRequest(string $file, string $worktree): array
    {
        if (str_starts_with($file, $worktree.'/')) {
            throw new InvalidArgumentException('Keep landing requests and secret handoffs outside the feature worktree.');
        }

        return self::object(json_decode(self::file($file, 262_144), true, 32, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public static function request(array $request): array
    {
        if (array_diff(array_keys($request), ['candidate', 'manifest', 'final_dispatch', 'issue_id', 'gate_receipt', 'pull_request_body', 'evidence_files']) !== []) {
            throw new InvalidArgumentException('Unknown landing request fields.');
        }
        foreach (['candidate' => 40, 'manifest' => 64] as $key => $length) {
            if (preg_match('/\A[a-f0-9]{'.$length.'}\z/', self::text($request, $key)) !== 1) {
                throw new InvalidArgumentException('Pin the exact landing '.$key.'.');
            }
        }
        if (! is_int($request['final_dispatch'] ?? null) || $request['final_dispatch'] < 1
            || preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/', self::text($request, 'issue_id')) !== 1) {
            throw new InvalidArgumentException('Pin the successful final dispatch and Linear issue UUID.');
        }
        $files = $request['evidence_files'] ?? [];
        if (! is_array($files) || ! array_is_list($files) || count($files) > 20) {
            throw new InvalidArgumentException('Use at most twenty explicit retained evidence files.');
        }
        $normalized = [];
        foreach ($files as $file) {
            $file = self::object($file);
            $name = self::text($file, 'name', 80);
            $sha = self::text($file, 'sha256', 64);
            if (array_diff(array_keys($file), ['name', 'path', 'sha256']) !== []
                || preg_match('/\A[a-z0-9][a-z0-9._-]*\z/', $name) !== 1
                || isset($normalized[$name]) || preg_match('/\A[a-f0-9]{64}\z/', $sha) !== 1) {
                throw new InvalidArgumentException('Evidence names and hashes must be unique and exact.');
            }
            $normalized[$name] = ['name' => $name, 'path' => self::text($file, 'path'), 'sha256' => $sha];
        }
        ksort($normalized);

        return ['candidate' => $request['candidate'], 'manifest' => $request['manifest'], 'final_dispatch' => $request['final_dispatch'],
            'issue_id' => $request['issue_id'], 'gate_receipt' => self::text($request, 'gate_receipt'),
            'pull_request_body' => self::text($request, 'pull_request_body', 50_000), 'evidence_files' => array_values($normalized)];
    }
}
