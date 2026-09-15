<?php

declare(strict_types=1);

namespace App\Tasks\Recovery;

use App\Tasks\GitObjectId;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * @phpstan-type AcceptedChild array{task_id: int, run_id: int, review_id: int, parent: string, commit: string, tree: string, worker_ref: string, reviewer_ref: string, provenance: array<string, mixed>}
 */
final readonly class TaskRecoveryEvidence
{
    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $workspace
     * @param  array<string, mixed>  $sourceSnapshot
     * @param  list<AcceptedChild>  $children
     */
    private function __construct(
        public string $path,
        public string $sha256,
        public string $sourcePath,
        public string $sourceSha256,
        public string $backupPath,
        public string $backupSha256,
        public array $manifest,
        public string $manifestHash,
        public array $workspace,
        public array $children,
        public array $sourceSnapshot,
    ) {}

    public static function load(string $path, string $backupPath): self
    {
        $contents = self::contents($path);
        $package = self::object(json_decode($contents, true, flags: JSON_THROW_ON_ERROR));
        if (($package['schema'] ?? null) !== 1) {
            throw new LogicException('Unsupported task recovery evidence schema.');
        }
        $source = self::object($package['source'] ?? null);
        $backup = self::object($package['backup'] ?? null);
        $sourcePath = self::string($source, 'path');
        $sourceContents = self::contents($sourcePath);
        $sourceHash = hash('sha256', $sourceContents);
        if (! hash_equals(self::string($source, 'sha256'), $sourceHash)
            || self::string($backup, 'path') !== $backupPath) {
            throw new LogicException('Recovery source or backup does not match the evidence package.');
        }
        $document = self::object(json_decode($sourceContents, true, flags: JSON_THROW_ON_ERROR));
        $manifest = self::object(self::pointer($document, self::string($package, 'manifest')));
        $manifestHash = self::pointer($document, self::string($package, 'manifest_hash'));
        $workspace = self::object(self::pointer($document, self::string($package, 'workspace')));
        foreach (['source_key', 'project_id', 'repository', 'worktree', 'base_sha'] as $field) {
            self::string($workspace, $field);
        }
        self::integer($workspace, 'root_task_id');
        GitObjectId::validate(self::string($workspace, 'base_sha'));
        if (! is_string($manifestHash) || ! hash_equals($manifestHash, hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR)))) {
            throw new LogicException('The source manifest does not match its recorded hash.');
        }
        $bindings = $package['accepted_children'] ?? null;
        if (! is_array($bindings) || ! array_is_list($bindings) || $bindings === []) {
            throw new LogicException('Recovery requires accepted-child source bindings.');
        }
        $children = [];
        foreach ($bindings as $binding) {
            $children[] = self::child($document, self::object($binding));
        }

        return new self($path, hash('sha256', $contents), $sourcePath, $sourceHash, $backupPath,
            self::string($backup, 'sha256'), $manifest, $manifestHash, $workspace, $children,
            self::sanitize($document));
    }

    public static function contents(string $path): string
    {
        if (! str_starts_with($path, '/') || realpath($path) !== $path || ! is_file($path) || is_link($path)) {
            throw new LogicException('Recovery inputs require existing canonical regular files.');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new LogicException('The recovery evidence file could not be read.');
        }

        return $contents;
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $references
     * @return AcceptedChild
     */
    private static function child(array $document, array $references): array
    {
        $records = [];
        foreach (['binding', 'approval', 'acknowledgment', 'worker', 'reviewer'] as $key) {
            $records[$key] = self::object(self::pointer($document, self::string($references, $key)));
        }
        $binding = $records['binding'];
        $approval = $records['approval'];
        self::string($approval, 'summary');
        self::string($approval, 'evidence_ref');
        $submit = $records['acknowledgment'];
        $ack = self::object($submit['acknowledgment'] ?? null);
        $handoff = self::object($submit['handoff'] ?? null);
        $worker = $records['worker'];
        $reviewer = $records['reviewer'];
        $taskId = self::integer($binding, 'task_id');
        $runId = self::integer($binding, 'run_id');
        $reviewId = self::integer($binding, 'passed_review_id');
        $parent = self::string($binding, 'parent');
        $commit = self::string($binding, 'commit');
        $tree = self::string($binding, 'tree');
        foreach ([$parent, $commit, $tree] as $sha) {
            GitObjectId::validate($sha);
            if (! str_contains(self::string($handoff, 'summary').' '.self::string($handoff, 'evidence'), $sha)) {
                throw new LogicException('The acknowledged commit handoff does not bind the parent, commit, and tree.');
            }
        }
        if (($approval['verdict'] ?? null) !== 'pass' || ($approval['tree_sha'] ?? null) !== $tree
            || ($approval['task_run_id'] ?? null) !== $runId || ($approval['id'] ?? null) !== $reviewId
            || ($worker['role'] ?? null) !== 'implementer' || ($worker['task_id'] ?? null) !== $taskId
            || ($reviewer['role'] ?? null) !== 'reviewer' || ($submit['type'] ?? null) !== 'submit_call'
            || ($handoff['verdict'] ?? null) !== null
            || ($ack['type'] ?? null) !== 'acknowledgment'
            || self::string($submit, 'call_id') !== self::string($ack, 'call_id')
            || self::string($submit, 'source_session') !== self::string($reviewer, 'session_path')
            || self::string($ack, 'source_session') !== self::string($submit, 'source_session')
            || self::string($ack, 'message') !== 'Handoff recorded. Stop work and wait for the next Commander instruction.') {
            throw new LogicException('Accepted-child approval, ownership, or native acknowledgment evidence is inconsistent.');
        }
        $reviewedAt = self::timestamp($approval, 'reviewed_at');
        $submittedAt = self::timestamp($submit, 'at');
        $acknowledgedAt = self::timestamp($ack, 'at');
        if ($submittedAt->lessThan($reviewedAt) || $acknowledgedAt->lessThan($submittedAt)) {
            throw new LogicException('The commit acknowledgment predates its approval or submission.');
        }
        $workerRef = self::string($worker, 'worker_ref');
        $reviewerRef = self::string($reviewer, 'reviewer_ref');
        if ($workerRef === $reviewerRef || mb_strlen($workerRef) > 255 || mb_strlen($reviewerRef) > 255) {
            throw new LogicException('Recovery requires distinct worker and reviewer identities.');
        }

        return ['task_id' => $taskId, 'run_id' => $runId, 'review_id' => $reviewId,
            'parent' => $parent, 'commit' => $commit, 'tree' => $tree, 'worker_ref' => $workerRef, 'reviewer_ref' => $reviewerRef,
            'provenance' => [
                'references' => $references,
                'binding' => array_intersect_key($binding, array_flip(['task_id', 'run_id', 'passed_review_id', 'parent', 'commit', 'tree'])),
                'approval' => array_intersect_key($approval, array_flip(['id', 'task_run_id', 'round', 'tree_sha', 'requested_at', 'reviewed_at', 'verdict', 'summary', 'evidence_ref'])),
                'acknowledgment' => array_intersect_key($ack, array_flip(['type', 'at', 'call_id', 'message', 'source_session'])),
                'handoff' => ['summary' => self::string($handoff, 'summary'), 'evidence' => self::string($handoff, 'evidence')],
                'observed_worker' => array_intersect_key($worker, array_flip(['role', 'task_id', 'worker_ref', 'codex_session', 'herdr_workspace', 'pane', 'session_path'])),
                'observed_reviewer' => array_intersect_key($reviewer, array_flip(['role', 'reviewer_ref', 'codex_session', 'herdr_workspace', 'pane', 'session_path'])),
            ]];
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $document
     * @return array<TKey, mixed>
     */
    private static function sanitize(array $document): array
    {
        foreach ($document as $key => $value) {
            if (is_string($key) && preg_match('/token|prompt|password|secret|api.?key|authorization/i', $key) === 1) {
                $document[$key] = '[credential or prompt omitted from recovery]';
            } elseif (is_array($value)) {
                $document[$key] = self::sanitize($value);
            }
        }

        return $document;
    }

    /** @return array<string, mixed> */
    public static function object(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new LogicException('Recovery evidence requires a JSON object.');
        }
        $object = [];
        foreach ($value as $key => $entry) {
            if (! is_string($key)) {
                throw new LogicException('Recovery object keys must be strings.');
            }
            $object[$key] = $entry;
        }

        return $object;
    }

    /** @param array<string, mixed> $value */
    public static function string(array $value, string $key): string
    {
        $result = $value[$key] ?? null;
        if (! is_string($result) || trim($result) === '' || str_contains($result, "\0")) {
            throw new LogicException('Missing or invalid recovery string: '.$key);
        }

        return $result;
    }

    /** @param array<string, mixed> $value */
    public static function integer(array $value, string $key): int
    {
        $result = $value[$key] ?? null;
        if (! is_int($result) || $result < 1) {
            throw new LogicException('Missing or invalid recovery identifier: '.$key);
        }

        return $result;
    }

    /** @param array<string, mixed> $value */
    private static function timestamp(array $value, string $key): CarbonImmutable
    {
        $timestamp = self::string($value, $key);
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/', $timestamp) !== 1) {
            throw new LogicException('Recovery evidence requires an exact original timestamp.');
        }

        return CarbonImmutable::parse($timestamp);
    }

    /** @param array<string, mixed> $document */
    private static function pointer(array $document, string $pointer): mixed
    {
        if (! str_starts_with($pointer, '/')) {
            throw new LogicException('Recovery references must be absolute JSON pointers.');
        }
        $value = $document;
        foreach (explode('/', substr($pointer, 1)) as $part) {
            $key = str_replace(['~1', '~0'], ['/', '~'], $part);
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                throw new LogicException('A referenced recovery source record is missing.');
            }
            $value = $value[$key];
        }

        return $value;
    }
}
