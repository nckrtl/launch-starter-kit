<?php

use App\Models\Task;
use App\Models\TaskAgentDispatch;
use App\Models\TaskRecovery;
use App\Models\TaskWorkspace;
use App\Projects\SharedKnowledgeProjectRepository;
use App\Tasks\Enums\TaskKind;
use App\Tasks\Recovery\RecoverAcceptedTasks;
use App\Tasks\Recovery\TaskRecoveryDatabase;
use App\Tasks\Runtime\TaskAgentPrompt;
use App\Tasks\Runtime\TaskProcessEnvironment;
use App\Tasks\Runtime\TaskRuntimePlan;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->recoveryDirectory = trim(Process::run(['mktemp', '-d', sys_get_temp_dir().'/commander-recovery-test-XXXXXX'])->throw()->output());
    $this->recoveryBackup = $this->recoveryDirectory.'/backup.sqlite';
    $this->recoveryTarget = $this->recoveryDirectory.'/quarantine.sqlite';
    $this->recoverySourcePath = $this->recoveryDirectory.'/source.json';
    $this->recoveryPackagePath = $this->recoveryDirectory.'/package.json';
    $this->recoveryRepository = $this->recoveryDirectory.'/repository';
    $this->recoveryWorktree = $this->recoveryDirectory.'/worktree';
    File::put($this->recoveryBackup, '');
    Process::path(base_path())->timeout(30)->env(array_merge(TaskProcessEnvironment::isolated(), [
        'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $this->recoveryDirectory.'/no-config.php',
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->recoveryBackup, 'DB_URL' => '',
        'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
        'COMMANDER_TASK_RUNTIME_ENABLED' => 'false',
    ]))->run([PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction'])->throw();
    DB::connectUsing('recovery-fixture', ['driver' => 'sqlite', 'database' => $this->recoveryBackup, 'foreign_key_constraints' => true]);
    $this->recoveryRoot = Task::on('recovery-fixture')->create(['project_id' => 'orbit', 'kind' => TaskKind::Group,
        'title' => 'Feature', 'description' => 'Integrate both tasks.', 'acceptance_criteria' => 'Both parts pass integrated verification.']);
    $children = [];
    foreach (['First task', 'Second task'] as $title) {
        $child = Task::on('recovery-fixture')->create(['project_id' => 'orbit', 'parent_id' => $this->recoveryRoot->id,
            'title' => $title, 'description' => 'Implement '.$title, 'acceptance_criteria' => 'The assigned part works.']);
        if ($children !== []) {
            $child->dependencies()->attach($children[0]->id);
        }
        $children[] = $child;
    }
    $manifest = app(TaskRuntimePlan::class)->manifest($this->recoveryRoot);
    $hash = app(TaskRuntimePlan::class)->hash($this->recoveryRoot);
    DB::purge('recovery-fixture');
    File::copy($this->recoveryBackup, $this->recoveryTarget);

    File::makeDirectory($this->recoveryRepository);
    recoveryGit(['init', '--initial-branch=main'], $this->recoveryRepository);
    recoveryGit(['config', 'user.email', 'recovery@example.test'], $this->recoveryRepository);
    recoveryGit(['config', 'user.name', 'Recovery Fixture'], $this->recoveryRepository);
    File::put($this->recoveryRepository.'/feature.txt', 'base');
    recoveryGit(['add', '.'], $this->recoveryRepository);
    recoveryGit(['commit', '-m', 'Base'], $this->recoveryRepository);
    recoveryGit(['worktree', 'add', '-b', 'feature', $this->recoveryWorktree], $this->recoveryRepository);
    $base = recoveryGit(['rev-parse', 'HEAD']);
    $sourceChildren = [];
    $references = [];
    $parent = $base;
    foreach ($children as $index => $child) {
        File::put($this->recoveryWorktree.'/feature.txt', 'accepted '.$index);
        recoveryGit(['add', '.']);
        recoveryGit(['commit', '-m', 'Accepted '.$index]);
        $commit = recoveryGit(['rev-parse', 'HEAD']);
        $tree = recoveryGit(['rev-parse', 'HEAD^{tree}']);
        $sourceChildren[] = [
            'binding' => ['task_id' => $child->id, 'run_id' => 100 + $index, 'passed_review_id' => 200 + $index,
                'parent' => $parent, 'commit' => $commit, 'tree' => $tree],
            'approval' => ['id' => 200 + $index, 'task_run_id' => 100 + $index, 'round' => 2,
                'verdict' => 'pass', 'tree_sha' => $tree, 'summary' => 'Original independent pass '.$index,
                'evidence_ref' => 'Original check evidence '.$index, 'reviewed_at' => '2026-09-10T12:00:00Z'],
            'acknowledgment' => ['type' => 'submit_call', 'call_id' => 'call-'.$index,
                'source_session' => '/retained/reviewer.jsonl', 'at' => '2026-09-10T12:01:00Z',
                'handoff' => ['summary' => 'Committed '.$commit, 'evidence' => 'Parent '.$parent.' tree '.$tree],
                'acknowledgment' => ['type' => 'acknowledgment', 'call_id' => 'call-'.$index,
                    'source_session' => '/retained/reviewer.jsonl', 'at' => '2026-09-10T12:01:01Z',
                    'message' => 'Handoff recorded. Stop work and wait for the next Commander instruction.']],
            'worker' => ['role' => 'implementer', 'task_id' => $child->id, 'worker_ref' => 'original-worker-'.$index],
        ];
        $references[] = ['binding' => '/children/'.$index.'/binding', 'approval' => '/children/'.$index.'/approval',
            'acknowledgment' => '/children/'.$index.'/acknowledgment', 'worker' => '/children/'.$index.'/worker', 'reviewer' => '/reviewer'];
        $parent = $commit;
    }
    $this->recoverySource = ['manifest' => $manifest, 'manifest_hash' => $hash,
        'workspace' => ['id' => 42, 'root_task_id' => $this->recoveryRoot->id, 'project_id' => 'orbit', 'source_key' => 'ORB-FIXTURE',
            'repository' => $this->recoveryRepository, 'worktree' => $this->recoveryWorktree, 'base_sha' => $base, 'manifest_hash' => $hash,
            'configuration' => ['flow_version' => 1, 'repository' => $this->recoveryRepository],
            'reviewer_session' => ['agentId' => 'observed-only'], 'herdr_workspace' => ['workspaceId' => 'observed-only']],
        'children' => $sourceChildren,
        'reviewer' => ['role' => 'reviewer', 'reviewer_ref' => 'original-reviewer', 'session_path' => '/retained/reviewer.jsonl'],
        'gaps' => ['Final integrated acceptance is absent.'],
        'exact_importable_facts' => ['Original approvals and commits survive.'],
        'derived_facts_requiring_recovered_provenance' => ['Original finish timestamps are missing.'],
        'original_reviews' => [['verdict' => 'revise', 'summary' => 'Earlier review finding retained.']],
        'lost_dispatch' => ['handoff_token' => 'must-not-recover-token', 'prompt' => 'must-not-recover-prompt'],
    ];
    $this->recoveryPackage = ['schema' => 1,
        'source' => ['path' => $this->recoverySourcePath, 'sha256' => 'pending'],
        'backup' => ['path' => $this->recoveryBackup, 'sha256' => hash_file('sha256', $this->recoveryBackup)],
        'manifest' => '/manifest', 'manifest_hash' => '/manifest_hash', 'workspace' => '/workspace', 'accepted_children' => $references];
    writeRecoveryEvidence($this);
    config(['task-runtime.enabled' => false]);
    Bus::fake();
    Queue::fake();
    $this->recoveryDefault = DB::getDefaultConnection();
    DB::listen(function (QueryExecuted $event) {
        if ($event->connectionName === $this->recoveryDefault) {
            throw new LogicException('Default database tripwire: recovery must never query the application database.');
        }
    });
});

afterEach(function () {
    DB::purge('recovery-fixture');
    File::deleteDirectory($this->recoveryDirectory);
});

function recoveryGit(array $arguments, ?string $path = null): string
{
    return trim(Process::path($path ?? test()->recoveryWorktree)->timeout(10)->env(array_merge(TaskProcessEnvironment::isolated(), [
        'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1',
    ]))->run(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'commit.gpgSign=false', ...$arguments])->throw()->output());
}

function writeRecoveryEvidence(object $test): void
{
    File::put($test->recoverySourcePath, json_encode($test->recoverySource, JSON_THROW_ON_ERROR));
    $test->recoveryPackage['source']['sha256'] = hash_file('sha256', $test->recoverySourcePath);
    File::put($test->recoveryPackagePath, json_encode($test->recoveryPackage, JSON_THROW_ON_ERROR));
}

function recoverFixture(bool $apply = false): array
{
    return app(RecoverAcceptedTasks::class)->handle('orbit', test()->recoveryRoot->id, test()->recoveryTarget,
        test()->recoveryBackup, test()->recoveryPackagePath, true, $apply);
}

it('dry-runs through the native command without changing databases Git or runtime state', function () {
    $targetHash = hash_file('sha256', $this->recoveryTarget);
    $backupHash = hash_file('sha256', $this->recoveryBackup);
    $refs = recoveryGit(['show-ref']);
    $this->artisan('tasks:recover-accepted', ['project' => 'orbit', 'task' => $this->recoveryRoot->id,
        '--database' => $this->recoveryTarget, '--backup' => $this->recoveryBackup,
        '--evidence' => $this->recoveryPackagePath, '--exclusive' => true])->assertSuccessful();

    expect(hash_file('sha256', $this->recoveryTarget))->toBe($targetHash)
        ->and(hash_file('sha256', $this->recoveryBackup))->toBe($backupHash)
        ->and(recoveryGit(['show-ref']))->toBe($refs)
        ->and(DB::getDefaultConnection())->toBe($this->recoveryDefault)
        ->and(File::exists($this->recoveryTarget.'-wal'))->toBeFalse();
    Bus::assertNothingDispatched();
    Queue::assertNothingPushed();
});

it('records current recovery acceptances with retained evidence while blocking the incomplete root', function () {
    $this->travelTo(now()->setDate(2026, 9, 12)->setTime(20, 0));
    $result = recoverFixture(true);
    $database = app(TaskRecoveryDatabase::class)->readOnly($this->recoveryTarget);
    $runs = $database->query('SELECT * FROM task_runs ORDER BY id')->fetchAll();
    $reviews = $database->query('SELECT * FROM task_run_reviews ORDER BY id')->fetchAll();
    $workspace = $database->query('SELECT * FROM task_workspaces')->fetch();
    $recovery = $database->query('SELECT * FROM task_recoveries')->fetch();
    $provenance = json_decode($recovery['provenance'], true, flags: JSON_THROW_ON_ERROR);
    expect($result['root_completed'])->toBeFalse()->and($runs)->toHaveCount(2)->and($reviews)->toHaveCount(2)
        ->and($database->query('SELECT COUNT(*) FROM tasks WHERE status = "completed"')->fetchColumn())->toBe(2)
        ->and($database->query('SELECT status FROM tasks WHERE parent_id IS NULL')->fetchColumn())->toBe('pending')
        ->and($workspace['reviewer_session'])->toBeNull()->and($workspace['herdr_workspace'])->toBeNull()
        ->and($workspace['final_check'])->toBeNull()->and($workspace['final_result'])->toBeNull()
        ->and($workspace['attention'])->toContain('Recovery '.$result['recovery_id'], 'final checks and review are pending')
        ->and($provenance['source_snapshot']['gaps'])->toBe($this->recoverySource['gaps'])
        ->and($provenance['source_snapshot']['original_reviews'])->toBe($this->recoverySource['original_reviews'])
        ->and($recovery['provenance'])->not->toContain('must-not-recover-token', 'must-not-recover-prompt')
        ->and($database->query('PRAGMA foreign_key_check')->fetchAll())->toBe([])
        ->and($database->query('PRAGMA integrity_check')->fetchColumn())->toBe('ok');
    foreach ($runs as $index => $run) {
        $input = json_decode($run['input'], true, flags: JSON_THROW_ON_ERROR);
        $output = json_decode($run['output'], true, flags: JSON_THROW_ON_ERROR);
        expect($run['idempotency_key'])->toStartWith('recovery:'.$result['recovery_id'])
            ->and($run['started_at'])->toBe(now()->toDateTimeString())
            ->and($run['finished_at'])->toBe(now()->toDateTimeString())
            ->and($run['active_task_id'])->toBeNull()->and($run['active_root_task_id'])->toBeNull()
            ->and($input['recovery']['source_run_id'])->toBe(100 + $index)
            ->and($output['evidence'])->toContain('not rerun', 'Original independent pass '.$index, 'Original check evidence '.$index)
            ->and($reviews[$index]['reviewed_at'])->toBe(now()->toDateTimeString())
            ->and($provenance['children'][$index]['source']['approval']['reviewed_at'])->toBe('2026-09-10T12:00:00Z');
    }
    foreach (['jobs', 'task_agent_dispatches'] as $table) {
        expect($database->query('SELECT COUNT(*) FROM '.$table)->fetchColumn())->toBe(0);
    }
    Bus::assertNothingDispatched();
    Queue::assertNothingPushed();
    DB::connectUsing('recovery-fixture', ['driver' => 'sqlite', 'database' => $this->recoveryTarget]);
    $record = TaskRecovery::on('recovery-fixture')->firstOrFail();
    expect(fn () => $record->update(['source_path' => '/changed']))->toThrow(LogicException::class, 'immutable')
        ->and(fn () => $record->delete())->toThrow(LogicException::class, 'immutable');
});

it('rolls back all recovered records if the provenance insert fails', function () {
    $fail = true;
    TaskRecovery::creating(function () use (&$fail): void {
        if ($fail) {
            throw new LogicException('Injected provenance failure.');
        }
    });
    try {
        expect(fn () => recoverFixture(true))->toThrow(LogicException::class, 'Injected provenance failure');
    } finally {
        $fail = false;
    }
    $database = app(TaskRecoveryDatabase::class)->readOnly($this->recoveryTarget);
    foreach (['task_runs', 'task_run_reviews', 'task_workspaces', 'task_recoveries', 'task_agent_dispatches'] as $table) {
        expect($database->query('SELECT COUNT(*) FROM '.$table)->fetchColumn())->toBe(0);
    }
    expect($database->query('SELECT COUNT(*) FROM tasks WHERE status != "pending"')->fetchColumn())->toBe(0);
});

it('keeps recovery evidence available to native inspection and the final reviewer', function () {
    $result = recoverFixture(true);
    config(['commander.projects_path' => $this->recoveryDirectory.'/projects']);
    app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
    $inspected = Process::path(base_path())->timeout(10)->env(array_merge(TaskProcessEnvironment::isolated(), [
        'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $this->recoveryDirectory.'/no-config.php',
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->recoveryTarget, 'DB_URL' => '',
        'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
        'COMMANDER_TASK_RUNTIME_ENABLED' => 'false', 'COMMANDER_PROJECTS_PATH' => $this->recoveryDirectory.'/projects',
    ]))->run([PHP_BINARY, 'artisan', 'tasks:inspect', 'orbit', (string) $this->recoveryRoot->id]);
    $inspection = json_decode($inspected->throw()->output(), true, flags: JSON_THROW_ON_ERROR);
    expect($inspection['recovery']['id'])->toBe($result['recovery_id'])
        ->and($inspection['recovery']['provenance']['source_snapshot']['gaps'])->toBe($this->recoverySource['gaps'])
        ->and($inspection['dispatches'])->toBe([]);
    DB::connectUsing('recovery-fixture', ['driver' => 'sqlite', 'database' => $this->recoveryTarget]);
    $workspace = TaskWorkspace::on('recovery-fixture')->firstOrFail();
    $dispatch = (new TaskAgentDispatch)->forceFill(['id' => 999, 'kind' => 'final_review', 'round' => 0]);
    $prompt = app(TaskAgentPrompt::class)->render($workspace, $dispatch, 'unused-fixture-token', null);
    expect($prompt)->toContain('RECOVERED ORIGINAL APPROVAL', 'Original check evidence 0', 'Original check evidence 1', 'not rerun');
    Bus::assertNothingDispatched();
    Queue::assertNothingPushed();
});

it('refuses repeat recovery instead of recreating accepted history', function () {
    recoverFixture(true);
    $before = hash_file('sha256', $this->recoveryTarget);
    expect(fn () => recoverFixture(true))->toThrow(LogicException::class)
        ->and(hash_file('sha256', $this->recoveryTarget))->toBe($before);
});

it('rejects missing malformed or inconsistent recovery evidence before writing', function (string $case) {
    if ($case === 'missing source') {
        $this->recoveryPackage['source']['path'] = $this->recoveryDirectory.'/missing.json';
    } elseif ($case === 'source digest') {
        $this->recoveryPackage['source']['sha256'] = str_repeat('0', 64);
    } elseif ($case === 'backup digest') {
        $this->recoveryPackage['backup']['sha256'] = str_repeat('0', 64);
    } elseif ($case === 'manifest') {
        $this->recoverySource['manifest']['children'][0]['description'] = 'Not the admitted brief.';
        $this->recoverySource['manifest_hash'] = hash('sha256', json_encode($this->recoverySource['manifest'], JSON_THROW_ON_ERROR));
        $this->recoverySource['workspace']['manifest_hash'] = $this->recoverySource['manifest_hash'];
        writeRecoveryEvidence($this);
    } elseif ($case === 'approval') {
        $this->recoverySource['children'][0]['approval']['verdict'] = 'revise';
        writeRecoveryEvidence($this);
    } elseif ($case === 'acknowledgment') {
        $this->recoverySource['children'][0]['acknowledgment']['acknowledgment']['call_id'] = 'different-call';
        writeRecoveryEvidence($this);
    } elseif ($case === 'blocked handoff') {
        $this->recoverySource['children'][0]['acknowledgment']['handoff']['verdict'] = 'blocked';
        writeRecoveryEvidence($this);
    } elseif ($case === 'missing approval') {
        unset($this->recoverySource['children'][0]['approval']);
        writeRecoveryEvidence($this);
    } elseif ($case === 'child order') {
        $this->recoveryPackage['accepted_children'] = array_reverse($this->recoveryPackage['accepted_children']);
    } elseif ($case === 'git head') {
        recoveryGit(['commit', '--allow-empty', '-m', 'Unapproved extra commit']);
    } elseif ($case === 'git tree') {
        $original = $this->recoverySource['children'][0]['binding']['tree'];
        $wrongTree = recoveryGit(['rev-parse', 'HEAD^{tree}']);
        $this->recoverySource['children'][0]['binding']['tree'] = $wrongTree;
        $this->recoverySource['children'][0]['approval']['tree_sha'] = $wrongTree;
        $this->recoverySource['children'][0]['acknowledgment']['handoff']['evidence'] = str_replace($original, $wrongTree,
            $this->recoverySource['children'][0]['acknowledgment']['handoff']['evidence']);
        writeRecoveryEvidence($this);
    }
    File::put($this->recoveryPackagePath, $case === 'malformed JSON' ? '{' : json_encode($this->recoveryPackage, JSON_THROW_ON_ERROR));
    $before = hash_file('sha256', $this->recoveryTarget);
    expect(fn () => recoverFixture(true))->toThrow($case === 'malformed JSON' ? JsonException::class : LogicException::class)
        ->and(hash_file('sha256', $this->recoveryTarget))->toBe($before);
})->with(['missing source', 'source digest', 'backup digest', 'manifest', 'approval', 'acknowledgment', 'blocked handoff', 'missing approval', 'child order', 'git head', 'git tree', 'malformed JSON']);

it('rejects target content or dependency changes relative to the backup', function (string $case) {
    $database = new PDO('sqlite:'.$this->recoveryTarget);
    $database->exec($case === 'brief' ? 'UPDATE tasks SET title = "Changed brief" WHERE parent_id IS NOT NULL' : 'DELETE FROM task_dependencies');
    $database = null;
    expect(fn () => recoverFixture(true))->toThrow(LogicException::class, 'differs from the pre-admission backup');
})->with(['brief', 'dependency']);

it('refuses configured live targets including database URL precedence and the fixed application path', function (string $case) {
    $originalBase = base_path();
    if ($case === 'configured URL' || $case === 'URL driver override') {
        config(['database.connections.sqlite.url' => 'sqlite:///'.$this->recoveryTarget,
            'database.connections.sqlite.database' => ':memory:']);
        if ($case === 'URL driver override') {
            config(['database.connections.sqlite.driver' => 'pgsql']);
        }
    } elseif ($case === 'configured SQLite URI') {
        config(['database.connections.sqlite.database' => 'file:'.$this->recoveryTarget.'?mode=rwc']);
    } elseif ($case === 'relative configured database') {
        app()->setBasePath($this->recoveryDirectory);
        config(['database.connections.sqlite.database' => 'quarantine.sqlite']);
    } elseif ($case === 'fixed application path') {
        $application = $this->recoveryDirectory.'/application';
        File::makeDirectory($application.'/database', recursive: true);
        File::copy($this->recoveryTarget, $application.'/database/database.sqlite');
        $this->recoveryTarget = $application.'/database/database.sqlite';
        app()->setBasePath($application);
        config(['database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null]);
    } else {
        config(['database.connections.sqlite.database' => $this->recoveryTarget]);
    }
    try {
        expect(fn () => recoverFixture(true))->toThrow(LogicException::class, 'refuses the application database');
    } finally {
        app()->setBasePath($originalBase);
    }
})->with(['configured database', 'configured URL', 'URL driver override', 'configured SQLite URI', 'relative configured database', 'fixed application path']);

it('requires explicit disabled exclusive offline quarantine ownership', function (string $case) {
    if ($case === 'runtime enabled') {
        config(['task-runtime.enabled' => true]);
    } elseif ($case === 'backup target') {
        $this->recoveryTarget = $this->recoveryBackup;
    } elseif ($case === 'hardlink') {
        link($this->recoveryTarget, $this->recoveryDirectory.'/alias.sqlite');
    } elseif ($case === 'symlink') {
        symlink($this->recoveryTarget, $this->recoveryDirectory.'/symlink.sqlite');
        $this->recoveryTarget = $this->recoveryDirectory.'/symlink.sqlite';
    } elseif ($case === 'WAL') {
        File::put($this->recoveryTarget.'-wal', 'uncheckpointed journal');
    }
    expect(fn () => app(RecoverAcceptedTasks::class)->handle('orbit', $this->recoveryRoot->id, $this->recoveryTarget,
        $this->recoveryBackup, $this->recoveryPackagePath, $case !== 'no exclusive', true))->toThrow(LogicException::class);
})->with(['runtime enabled', 'backup target', 'hardlink', 'symlink', 'WAL', 'no exclusive']);

it('reports a missing additive migration in dry-run and never migrates the target itself', function () {
    $database = new PDO('sqlite:'.$this->recoveryTarget);
    $database->exec('DROP TABLE task_recoveries');
    $database = null;
    expect(recoverFixture()['migration_required'])->toBeTrue()
        ->and(fn () => recoverFixture(true))->toThrow(LogicException::class, 'Apply the task recovery migration');
});

it('rejects sidecar-free WAL backup and target files without creating sidecars or changing either input', function (string $input, bool $apply) {
    $path = $input === 'backup' ? $this->recoveryBackup : $this->recoveryTarget;
    $writer = new PDO('sqlite:'.$path);
    expect($writer->query('PRAGMA journal_mode=WAL')->fetchColumn())->toBe('wal');
    $writer = null;
    expect(substr(File::get($path), 18, 2))->toBe("\x02\x02");
    foreach (['-wal', '-shm', '-journal'] as $suffix) {
        expect(File::exists($path.$suffix))->toBeFalse();
    }
    $this->recoveryPackage['backup']['sha256'] = hash_file('sha256', $this->recoveryBackup);
    writeRecoveryEvidence($this);
    $directory = scandir($this->recoveryDirectory);
    $backupHash = hash_file('sha256', $this->recoveryBackup);
    $targetHash = hash_file('sha256', $this->recoveryTarget);

    expect(fn () => recoverFixture($apply))->toThrow(LogicException::class, 'WAL-format or invalid inputs are not opened')
        ->and(fn () => app(TaskRecoveryDatabase::class)->readOnly($path))
        ->toThrow(LogicException::class, 'WAL-format or invalid inputs are not opened')
        ->and(scandir($this->recoveryDirectory))->toBe($directory)
        ->and(hash_file('sha256', $this->recoveryBackup))->toBe($backupHash)
        ->and(hash_file('sha256', $this->recoveryTarget))->toBe($targetHash);
})->with([
    'backup dry-run' => ['backup', false], 'target dry-run' => ['target', false],
    'backup apply' => ['backup', true], 'target apply' => ['target', true],
]);
