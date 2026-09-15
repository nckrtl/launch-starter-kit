<?php

declare(strict_types=1);

namespace App\Tasks\Landing;

use App\Models\TaskWorkspace;
use App\Tasks\GitObjectId;
use App\Tasks\Runtime\TaskProcessEnvironment;
use App\Tasks\Runtime\TaskReattemptGit;
use Illuminate\Support\Facades\Process;
use LogicException;

final readonly class TaskLandingReattemptGit
{
    public function __construct(private TaskReattemptGit $git) {}

    /** @param array<string, mixed> $transition
     * @param  list<array<string, mixed>>  $accepted
     * @return array<string, mixed>
     */
    public function proof(TaskWorkspace $workspace, array $transition, array $accepted): array
    {
        $checkpoint = TaskLandingData::object($transition['checkpoint'] ?? null);
        $cutover = TaskLandingData::object($transition['cutover'] ?? null);
        $preserved = $this->observation($checkpoint['observation'] ?? null);
        $restored = $this->observation($cutover['observation'] ?? null);
        $request = TaskLandingData::object($checkpoint['request'] ?? null);
        $prerequisite = TaskLandingData::object($request['prerequisite'] ?? null);
        $candidate = TaskLandingData::text($prerequisite, 'candidate');
        $merge = TaskLandingData::text($prerequisite, 'merge');
        $main = TaskLandingData::text($prerequisite, 'main');
        $identity = TaskLandingData::text($checkpoint, 'request_hash');
        $this->git->assertRetained($workspace->worktree, $identity, $preserved);
        $preserveHistory = ($request['integration'] ?? null) === 'preserve_history';
        $this->git->prerequisite($workspace->worktree, $preserved['head'], $candidate, $merge, $main, remote: false, preserveHistory: $preserveHistory);
        $base = $main;
        if ($preserveHistory) {
            $this->git->integration($workspace->worktree, $preserved['head'], $main, $restored['head']);
            $base = $restored['head'];
        }
        $expected = $this->git->restored($workspace->worktree, $preserved, $base);
        if ($restored['head'] !== $base || $restored['tree'] !== $expected['tree'] || $restored['index_tree'] !== $expected['index_tree']) {
            throw new LogicException('The audited cutover did not restore the exact preserved working and staged trees.');
        }
        $current = $this->git->observe($workspace->repository, $workspace->worktree);
        foreach (['branch', 'git_directory', 'common_directory'] as $key) {
            if ($current[$key] !== $preserved[$key] || $restored[$key] !== $preserved[$key]) {
                throw new LogicException('The landing worktree no longer owns the preserved reattempt Git assignment.');
            }
        }
        $commits = [];
        $lastTree = null;
        foreach ($accepted as $entry) {
            $commit = TaskLandingData::text($entry, 'commit_sha');
            $tree = TaskLandingData::text($entry, 'tree_sha');
            GitObjectId::validate($commit);
            GitObjectId::validate($tree);
            if (($entry['base_sha'] ?? null) !== $base
                || $this->read($workspace, ['show', '--no-patch', '--no-notes', '--no-show-signature', '--format=%H %T %P', $commit]) !== $commit.' '.$tree.' '.$base
                || $this->read($workspace, ['cat-file', '-t', $tree]) !== 'tree') {
                throw new LogicException('Every reattempt-chain commit must have its passing reviewed tree and exactly its recorded base as sole parent.');
            }
            $commits[] = ['commit' => $commit, 'tree' => $tree, 'parent' => $base];
            $base = $commit;
            $lastTree = $tree;
        }
        if ($commits === [] || $current['head'] !== $base || $current['tree'] !== $lastTree || $current['index_tree'] !== $lastTree) {
            throw new LogicException('The clean landing candidate must remain the last accepted reattempt-chain commit.');
        }
        $history = $transition['manifest_history'] ?? null;
        if (! is_array($history) || ! array_is_list($history)) {
            throw new LogicException('Missing reattempt manifest history.');
        }
        foreach ($history as $continuation) {
            if (! in_array(TaskLandingData::text(TaskLandingData::object($continuation), 'head'), array_column($commits, 'commit'), true)) {
                throw new LogicException('A final continuation does not belong to the accepted reattempt chain.');
            }
        }
        $this->git->assertRetained($workspace->worktree, $identity, $preserved);

        return ['schema' => 1, 'candidate' => $base, 'tree' => $lastTree, 'branch' => $current['branch'],
            'git_directory' => $current['git_directory'], 'common_directory' => $current['common_directory'],
            'retained_refs' => ['refs/commander/task-reattempts/'.$identity.'/tree' => $preserved['tree'],
                'refs/commander/task-reattempts/'.$identity.'/index_tree' => $preserved['index_tree']],
            'restored' => $expected, 'accepted_commits' => $commits];
    }

    /** @return array<string, string> */
    private function observation(mixed $value): array
    {
        $observation = TaskLandingData::object($value);
        $result = [];
        foreach (['head', 'branch', 'git_directory', 'common_directory', 'tree', 'index_tree', 'index_sha256'] as $key) {
            $result[$key] = TaskLandingData::text($observation, $key);
        }

        return $result;
    }

    /** @param list<string> $arguments */
    private function read(TaskWorkspace $workspace, array $arguments): string
    {
        $result = Process::path($workspace->worktree)->timeout(30)->env([...TaskProcessEnvironment::isolated(),
            'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_TERMINAL_PROMPT' => '0',
            'GIT_OPTIONAL_LOCKS' => '0', 'GIT_NO_LAZY_FETCH' => '1', 'GIT_NO_REPLACE_OBJECTS' => '1', 'LC_ALL' => 'C',
        ])->run(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', ...$arguments]);
        if ($result->failed()) {
            throw new LogicException('The local reattempt accepted-commit evidence is missing or invalid.');
        }

        return rtrim($result->output(), "\n");
    }
}
