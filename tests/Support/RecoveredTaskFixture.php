<?php

namespace Tests\Support;

use App\Models\Task;
use App\Models\TaskWorkspace;
use App\Tasks\Enums\TaskKind;
use App\Tasks\Recovery\RecoverAcceptedTasks;
use App\Tasks\Recovery\ResumeRecoveredTaskWorkspace;
use App\Tasks\Runtime\TaskProcessEnvironment;
use App\Tasks\Runtime\TaskRuntimePlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

final class RecoveredTaskFixture
{
    public string $directory;

    public string $database;

    public string $backup;

    public string $repository;

    public string $worktree;

    public array $source;

    public array $package;

    public array $agent;

    public array $snapshot;

    public array $sequences = [];

    public ?FakeHerdrServer $server = null;

    public string $recoveryId;

    public int $workspaceId;

    public int $rootId;

    public string $head;

    public function __construct()
    {
        $this->directory = trim(Process::run(['mktemp', '-d', sys_get_temp_dir().'/commander-resume-test-XXXXXX'])->throw()->output());
        $this->database = $this->directory.'/runtime.sqlite';
        $this->backup = $this->directory.'/backup.sqlite';
        $this->repository = $this->directory.'/repository';
        $this->worktree = $this->directory.'/worktrees/feature';
        File::put($this->backup, '');
        Process::path(base_path())->timeout(30)->env(array_merge(TaskProcessEnvironment::isolated(), [
            'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $this->directory.'/no-config.php',
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->backup, 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
            'COMMANDER_TASK_RUNTIME_ENABLED' => 'false',
        ]))->run([PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction'])->throw();
        DB::connectUsing('resume-source', ['driver' => 'sqlite', 'database' => $this->backup, 'foreign_key_constraints' => true]);
        $root = Task::on('resume-source')->create(['project_id' => 'orbit', 'kind' => TaskKind::Group,
            'title' => 'Feature', 'description' => 'Integrate both tasks.', 'acceptance_criteria' => 'Integrated evidence passes.']);
        $this->rootId = $root->id;
        $children = [];
        foreach (['First', 'Second'] as $title) {
            $child = Task::on('resume-source')->create(['project_id' => 'orbit', 'parent_id' => $root->id,
                'title' => $title, 'description' => 'Implement '.$title, 'acceptance_criteria' => 'Focused acceptance passes.']);
            if ($children !== []) {
                $child->dependencies()->attach($children[0]->id);
            }
            $children[] = $child;
        }
        $manifest = app(TaskRuntimePlan::class)->manifest($root);
        $hash = app(TaskRuntimePlan::class)->hash($root);
        DB::purge('resume-source');
        File::copy($this->backup, $this->database);
        File::makeDirectory($this->repository);
        File::makeDirectory(dirname($this->worktree));
        $this->git(['init', '--initial-branch=main'], $this->repository);
        $this->git(['config', 'user.email', 'resume@example.test'], $this->repository);
        $this->git(['config', 'user.name', 'Resume Fixture'], $this->repository);
        File::put($this->repository.'/feature.txt', 'base');
        $this->git(['add', '.'], $this->repository);
        $this->git(['commit', '-m', 'Base'], $this->repository);
        $this->git(['worktree', 'add', '-b', 'feature', $this->worktree], $this->repository);
        $base = $parent = $this->git(['rev-parse', 'HEAD']);
        $accepted = $references = [];
        foreach ($children as $index => $child) {
            File::put($this->worktree.'/feature.txt', 'accepted '.$index);
            $this->git(['add', '.']);
            $this->git(['commit', '-m', 'Accepted '.$index]);
            $commit = $this->git(['rev-parse', 'HEAD']);
            $tree = $this->git(['rev-parse', 'HEAD^{tree}']);
            $accepted[] = [
                'binding' => ['task_id' => $child->id, 'run_id' => 100 + $index, 'passed_review_id' => 200 + $index,
                    'parent' => $parent, 'commit' => $commit, 'tree' => $tree],
                'approval' => ['id' => 200 + $index, 'task_run_id' => 100 + $index, 'round' => 2, 'verdict' => 'pass',
                    'tree_sha' => $tree, 'summary' => 'Original pass '.$index, 'evidence_ref' => 'Original check '.$index, 'reviewed_at' => '2026-09-10T12:00:00Z'],
                'acknowledgment' => ['type' => 'submit_call', 'call_id' => 'call-'.$index, 'source_session' => '/retained/reviewer.jsonl', 'at' => '2026-09-10T12:01:00Z',
                    'handoff' => ['summary' => 'Committed '.$commit, 'evidence' => 'Parent '.$parent.' tree '.$tree],
                    'acknowledgment' => ['type' => 'acknowledgment', 'call_id' => 'call-'.$index, 'source_session' => '/retained/reviewer.jsonl',
                        'at' => '2026-09-10T12:01:01Z', 'message' => 'Handoff recorded. Stop work and wait for the next Commander instruction.']],
                'worker' => ['role' => 'implementer', 'task_id' => $child->id, 'worker_ref' => 'historical-worker-'.$index],
            ];
            $references[] = ['binding' => '/children/'.$index.'/binding', 'approval' => '/children/'.$index.'/approval',
                'acknowledgment' => '/children/'.$index.'/acknowledgment', 'worker' => '/children/'.$index.'/worker', 'reviewer' => '/reviewer'];
            $parent = $commit;
        }
        $this->head = $parent;
        $this->source = ['manifest' => $manifest, 'manifest_hash' => $hash,
            'workspace' => ['id' => 42, 'root_task_id' => $root->id, 'project_id' => 'orbit', 'source_key' => 'ORB-RESUME',
                'repository' => $this->repository, 'worktree' => $this->worktree, 'base_sha' => $base, 'manifest_hash' => $hash,
                'configuration' => ['flow_version' => 1, 'repository' => $this->repository, 'worktree_root' => dirname($this->worktree),
                    'socket' => '/will-be-fake.sock', 'agent_kind' => 'codex', 'agent_arguments' => [], 'instructions' => 'Review integrated acceptance.',
                    'final_command' => [PHP_BINARY, '-r', 'echo "Fresh isolated final check";'], 'final_timeout' => 5],
                'final_check' => ['output' => 'TAINTED OLD CHECK', 'exit_code' => 0]],
            'children' => $accepted,
            'reviewer' => ['role' => 'reviewer', 'reviewer_ref' => 'task-w42-reviewer', 'codex_session' => 'retained-thread',
                'herdr_workspace' => 'w42', 'pane' => 'p42', 'session_path' => '/retained/reviewer.jsonl'],
            'lost_dispatch' => ['prompt' => 'OLD PROMPT MUST NEVER REPLAY', 'handoff_token' => 'old-token'],
        ];
        $this->package = ['schema' => 1, 'source' => ['path' => $this->directory.'/source.json', 'sha256' => 'pending'],
            'backup' => ['path' => $this->backup, 'sha256' => hash_file('sha256', $this->backup)],
            'manifest' => '/manifest', 'manifest_hash' => '/manifest_hash', 'workspace' => '/workspace', 'accepted_children' => $references];
        $this->agent = ['workspace_id' => 'w42', 'tab_id' => 't42', 'pane_id' => 'p42', 'terminal_id' => 'term42',
            'agent' => 'codex', 'name' => 'task-w42-reviewer', 'cwd' => $this->worktree, 'agent_status' => 'working', 'state_change_seq' => 7,
            'agent_session' => ['agent' => 'codex', 'kind' => 'thread', 'source' => 'herdr:codex', 'value' => 'retained-thread']];
        $this->snapshot = ['version' => 'fixture', 'protocol' => 22, 'tabs' => [], 'layouts' => [],
            'workspaces' => [['workspace_id' => 'w42', 'worktree' => ['repo_root' => $this->repository, 'checkout_path' => $this->worktree, 'is_linked_worktree' => true]]],
            'panes' => [$this->agent], 'agents' => [$this->agent]];
    }

    public function recover(): void
    {
        $this->server = FakeHerdrServer::start(['agents' => [], 'workspaces' => [], 'events' => [], 'rpc' => [
            'session.snapshot' => ['type' => 'session_snapshot', 'snapshot' => $this->snapshot],
            'agent.get' => ['type' => 'agent_info', 'agent' => $this->agent],
            'agent.prompt' => ['type' => 'agent_prompted', 'agent' => $this->agent],
        ], 'rpc_sequences' => $this->sequences]);
        $this->source['workspace']['configuration']['socket'] = $this->server->socketPath;
        $this->writeEvidence();
        config(['task-runtime.enabled' => false]);
        $result = app(RecoverAcceptedTasks::class)->handle('orbit', $this->rootId, $this->database, $this->backup, $this->directory.'/package.json', true, true);
        $this->workspaceId = $result['workspace_id'];
        $this->recoveryId = $result['recovery_id'];
        config(['database.connections.resume-fixture' => ['driver' => 'sqlite', 'database' => $this->database,
            'foreign_key_constraints' => true, 'transaction_mode' => 'IMMEDIATE']]);
        DB::setDefaultConnection('resume-fixture');
    }

    public function writeEvidence(): void
    {
        File::put($this->directory.'/source.json', json_encode($this->source, JSON_THROW_ON_ERROR));
        $this->package['source']['sha256'] = hash_file('sha256', $this->directory.'/source.json');
        File::put($this->directory.'/package.json', json_encode($this->package, JSON_THROW_ON_ERROR));
    }

    public function resume(bool $apply = false, bool $advance = false): array
    {
        return app(ResumeRecoveredTaskWorkspace::class)->handle($this->workspaceId, $this->database, $this->recoveryId, $this->head, true, $apply, $advance);
    }

    public function workspace(): TaskWorkspace
    {
        return TaskWorkspace::query()->findOrFail($this->workspaceId);
    }

    public function git(array $arguments, ?string $path = null): string
    {
        return trim(Process::path($path ?? $this->worktree)->timeout(10)->env(array_merge(TaskProcessEnvironment::isolated(), [
            'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1',
        ]))->run(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'commit.gpgSign=false', ...$arguments])->throw()->output());
    }

    public function close(): void
    {
        DB::purge('resume-source');
        DB::purge('resume-fixture');
        DB::setDefaultConnection('sqlite');
        $this->server?->stop();
        File::deleteDirectory($this->directory);
    }
}
