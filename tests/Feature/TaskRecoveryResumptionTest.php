<?php

use App\Delivery\Enums\DeliveryStatus;
use App\Jobs\AdvanceTaskRunner;
use App\Models\Delivery;
use App\Models\ProjectOrchestration;
use App\Models\Task;
use App\Models\TaskAgentDispatch;
use App\Models\TaskRecovery;
use App\Models\TaskRecoveryResumption;
use App\Models\TaskRun;
use App\Models\TaskRunReview;
use App\Projects\SharedKnowledgeProjectRepository;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\Recovery\ResumeRecoveredTaskWorkspace;
use App\Tasks\Runtime\AdvanceTaskWorkspace;
use App\Tasks\Runtime\SubmitTaskDispatch;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecoveredTaskFixture;
use Tests\Support\UsesTaskSharedLocks;

uses(UsesTaskSharedLocks::class);

beforeEach(function () {
    $this->fixture = new RecoveredTaskFixture;
    Bus::fake();
    Queue::fake();
});

afterEach(fn () => $this->fixture->close());

it('previews through the CLI with no database writes Git ref changes queue entries or mutating RPCs', function () {
    $f = $this->fixture;
    $f->recover();
    $before = hash_file('sha256', $f->database);
    $refs = $f->git(['show-ref']);
    $queries = [];
    DB::listen(function (QueryExecuted $event) use (&$queries): void {
        $queries[] = $event->sql;
        if ($event->connectionName === 'resume-fixture') {
            throw new LogicException('Preview accessed the writable default connection.');
        }
    });
    $this->artisan('tasks:resume-recovered', ['workspace' => $f->workspaceId, '--database' => $f->database,
        '--recovery' => $f->recoveryId, '--head' => $f->head, '--exclusive' => true])->assertSuccessful();
    expect(hash_file('sha256', $f->database))->toBe($before)->and($f->git(['show-ref']))->toBe($refs)
        ->and(array_filter($queries, fn ($sql) => preg_match('/^(insert|update|delete|create|alter|drop)\b/i', $sql)))->toBe([])
        ->and(array_column($f->server->requests(), 'method'))->toBe(['session.snapshot', 'agent.get'])
        ->and(File::exists($f->database.'-wal'))->toBeFalse()->and(File::exists($f->database.'-shm'))->toBeFalse();
    Bus::assertNothingDispatched();
    Queue::assertNothingPushed();
});

it('resumes a renumbered workspace with the working retained reviewer while keeping the root incomplete', function () {
    $f = $this->fixture;
    $f->recover();
    $source = TaskRecovery::query()->firstOrFail()->toArray();
    $runs = TaskRun::query()->get()->toArray();
    $result = $f->resume(true);
    expect($result['resume_recorded'])->toBeTrue()->and($result['advancement'])->toBe('not_requested')
        ->and($f->workspaceId)->not->toBe(42)
        ->and($f->workspace()->reviewer_session)->toMatchArray(['agentName' => 'task-w42-reviewer', 'agentId' => 'retained-thread', 'agentStatus' => 'working'])
        ->and($f->workspace()->herdr_workspace)->toBe(['workspaceId' => 'w42', 'paneId' => 'p42'])
        ->and($f->workspace()->attention)->toBeNull()->and($f->workspace()->final_check)->toBeNull()->and($f->workspace()->final_result)->toBeNull()
        ->and(Task::query()->findOrFail($f->rootId)->status)->toBe(TaskStatus::Pending)
        ->and(TaskRecovery::query()->firstOrFail()->toArray())->toBe($source)
        ->and(TaskRun::query()->get()->toArray())->toBe($runs)
        ->and(TaskAgentDispatch::query()->count())->toBe(0)->and(TaskRecoveryResumption::query()->count())->toBe(1);
    $audit = TaskRecoveryResumption::query()->firstOrFail();
    expect(fn () => $audit->update(['head' => str_repeat('a', 40)]))->toThrow(LogicException::class, 'immutable')
        ->and(fn () => $audit->delete())->toThrow(LogicException::class, 'immutable');
    Bus::assertNothingDispatched();
    Queue::assertNothingPushed();
});

it('rejects stale recovery database and head pins and requires explicit operator attestation', function (string $case) {
    $f = $this->fixture;
    $f->recover();
    expect(fn () => app(ResumeRecoveredTaskWorkspace::class)->handle($f->workspaceId,
        $case === 'database' ? $f->backup : $f->database,
        $case === 'recovery' ? '00000000-0000-4000-8000-000000000000' : $f->recoveryId,
        $case === 'head' ? str_repeat('a', 40) : $f->head, $case !== 'exclusive', true))->toThrow($case === 'recovery' ? ModelNotFoundException::class : LogicException::class);
    expect($f->workspace()->attention)->not->toBeNull()->and(TaskRecoveryResumption::query()->count())->toBe(0);
    Bus::assertNothingDispatched();
})->with(['database', 'recovery', 'head', 'exclusive']);

it('rejects changed runtime records and evidence without clearing the original hold', function (string $case) {
    $f = $this->fixture;
    $f->recover();
    switch ($case) {
        case 'package': File::append($f->directory.'/package.json', "\n");
            break;
        case 'source': File::append($f->directory.'/source.json', "\n");
            break;
        case 'backup': File::append($f->backup, 'changed');
            break;
        case 'manifest': DB::table('tasks')->where('id', $f->rootId)->update(['description' => 'Changed root']);
            break;
        case 'provenance':
            $record = TaskRecovery::query()->firstOrFail();
            $provenance = $record->provenance;
            $provenance['source_snapshot']['workspace']['final_check']['output'] = 'Changed history';
            DB::table('task_recoveries')->update(['provenance' => json_encode($provenance)]);
            break;
        case 'mapping':
            $record = TaskRecovery::query()->firstOrFail();
            $provenance = $record->provenance;
            $provenance['children'][0]['recovered_run_id'] = 999;
            DB::table('task_recoveries')->update(['provenance' => json_encode($provenance)]);
            break;
        case 'configuration':
            $configuration = $f->workspace()->configuration;
            $configuration['instructions'] = 'Changed admission';
            DB::table('task_workspaces')->update(['configuration' => json_encode($configuration)]);
            break;
        case 'other hold': $f->workspace()->update(['attention' => 'Unrelated hold']);
            break;
        case 'session': $f->workspace()->update(['reviewer_session' => ['agentName' => 'replacement']]);
            break;
        case 'final check': $f->workspace()->update(['final_check' => ['exit_code' => 0]]);
            break;
        case 'final result': $f->workspace()->update(['final_result' => ['verdict' => 'pass']]);
            break;
        case 'run': DB::table('task_runs')->where('id', 1)->update(['commit_sha' => str_repeat('a', 40)]);
            break;
        case 'review': DB::table('task_run_reviews')->where('id', 1)->update(['verdict' => 'revise']);
            break;
        case 'evidence output': DB::table('task_runs')->where('id', 1)->update(['output' => '{}']);
            break;
        case 'root complete': DB::table('tasks')->where('id', $f->rootId)->update(['status' => 'completed']);
            break;
    }
    $before = hash_file('sha256', $f->database);
    expect(fn () => $f->resume(true))->toThrow(LogicException::class)
        ->and(hash_file('sha256', $f->database))->toBe($before)
        ->and(TaskRecoveryResumption::query()->count())->toBe(0);
    Bus::assertNothingDispatched();
})->with(['package', 'source', 'backup', 'manifest', 'provenance', 'mapping', 'configuration', 'other hold', 'session', 'final check', 'final result', 'run', 'review', 'evidence output', 'root complete']);

it('refuses incomplete or unsafe admitted configuration without replacing it from current defaults', function (string $case) {
    $f = $this->fixture;
    $f->source['workspace']['configuration'][$case] = match ($case) {
        'instructions' => '', 'worktree_root' => $f->directory.'/repository', 'final_timeout' => 0,
        'final_command' => ['command' => 'composer'], 'agent_arguments' => [false],
    };
    $f->recover();
    expect(fn () => $f->resume())->toThrow(LogicException::class);
})->with(['instructions', 'worktree_root', 'final_timeout', 'final_command', 'agent_arguments']);

it('rejects replaced reviewer identity before any mutation or prompt', function (string $case) {
    $f = $this->fixture;
    if ($case === 'missing conversation') {
        $f->agent['agent_session'] = null;
    } elseif ($case === 'conversation') {
        $f->agent['agent_session']['value'] = 'replacement';
    } elseif ($case === 'repository') {
        $f->snapshot['workspaces'][0]['worktree']['repo_root'] = '/replacement';
    } elseif ($case === 'missing pane') {
        $f->snapshot['panes'] = [];
    } elseif ($case === 'snapshot agent') {
        $f->snapshot['agents'][0]['terminal_id'] = 'replacement';
    } else {
        $f->agent[$case] = 'replacement';
    }
    $f->recover();
    expect(fn () => $f->resume(true))->toThrow(LogicException::class)
        ->and(TaskRecoveryResumption::query()->count())->toBe(0)->and($f->workspace()->attention)->not->toBeNull()
        ->and(array_diff(array_column($f->server->requests(), 'method'), ['session.snapshot', 'agent.get']))->toBe([]);
})->with(['workspace_id', 'tab_id', 'pane_id', 'terminal_id', 'name', 'cwd', 'missing conversation', 'conversation', 'repository', 'missing pane', 'snapshot agent']);

it('requires one non-null reviewer conversation across every child source', function (bool $missing) {
    $f = $this->fixture;
    if ($missing) {
        unset($f->source['reviewer']['codex_session']);
    } else {
        $f->source['other_reviewer'] = [...$f->source['reviewer'], 'codex_session' => 'other-thread'];
        $f->package['accepted_children'][1]['reviewer'] = '/other_reviewer';
    }
    $f->recover();
    expect(fn () => $f->resume())->toThrow(LogicException::class);
})->with([false, true]);

it('rolls back binding and audit on persistence or second observation failure', function (bool $replacement) {
    $f = $this->fixture;
    if ($replacement) {
        $changed = $f->agent;
        $changed['agent_session']['value'] = 'replacement';
        $f->sequences['agent.get'] = [['result' => ['type' => 'agent_info', 'agent' => $f->agent]], ['result' => ['type' => 'agent_info', 'agent' => $changed]]];
    }
    $f->recover();
    $fail = ! $replacement;
    TaskRecoveryResumption::creating(function () use (&$fail): void {
        if ($fail) {
            throw new LogicException('Injected audit failure');
        }
    });
    try {
        expect(fn () => $f->resume(true))->toThrow(LogicException::class);
    } finally {
        $fail = false;
    }
    expect(TaskRecoveryResumption::query()->count())->toBe(0)->and($f->workspace()->reviewer_session)->toBeNull()
        ->and($f->workspace()->attention)->not->toBeNull();
    Bus::assertNothingDispatched();
})->with([false, true]);

it('returns the existing audit on identical retries without enqueueing again and rejects conflicting retries', function () {
    $f = $this->fixture;
    $f->recover();
    config(['task-runtime.enabled' => true]);
    $first = $f->resume(true, true);
    $again = $f->resume(true, true);
    expect($again['audit'])->toBe($first['audit'])->and($again['mode'])->toBe('already_recorded');
    Bus::assertDispatchedTimes(AdvanceTaskRunner::class, 1);
    expect(fn () => $f->resume(true))->toThrow(LogicException::class, 'conflicting');
});

it('requires apply and enabled runtime for advancement but permits disabled binding alone', function (bool $apply) {
    $f = $this->fixture;
    $f->recover();
    expect(fn () => $f->resume($apply, true))->toThrow(LogicException::class, 'Advancement requires');
    expect(TaskRecoveryResumption::query()->count())->toBe(0);
    Bus::assertNothingDispatched();
})->with([false, true]);

it('reports committed resumption and pending advancement when the post-commit enqueue fails', function () {
    $f = $this->fixture;
    $f->recover();
    config(['task-runtime.enabled' => true]);
    Bus::shouldReceive('dispatch')->once()->withArgs(function ($job) use ($f): bool {
        expect(DB::transactionLevel())->toBe(0)->and($f->workspace()->attention)->toBeNull()
            ->and(TaskRecoveryResumption::query()->count())->toBe(1);

        return $job instanceof AdvanceTaskRunner;
    })->andThrow(new RuntimeException('Fixture queue unavailable'));
    $this->artisan('tasks:resume-recovered', ['workspace' => $f->workspaceId, '--database' => $f->database,
        '--recovery' => $f->recoveryId, '--head' => $f->head, '--exclusive' => true, '--apply' => true, '--advance' => true])
        ->expectsOutputToContain('Resume recorded; advancement pending')->assertFailed();
    expect($f->workspace()->attention)->toBeNull()->and(TaskRecoveryResumption::query()->count())->toBe(1)
        ->and($f->resume(true, true)['mode'])->toBe('already_recorded');
});

it('uses only a fresh final check and final-review dispatch after resumption', function (string $verdict) {
    $f = $this->fixture;
    if ($verdict === 'check fails') {
        $f->source['workspace']['configuration']['final_command'] = [PHP_BINARY, '-r', 'fwrite(STDERR, "Fresh failure"); exit(1);'];
    }
    $f->recover();
    $f->resume(true);
    config(['task-runtime.enabled' => true]);
    if ($verdict === 'check fails') {
        $dispatch = app(AdvanceTaskWorkspace::class)->handle($f->workspace());
        expect($dispatch->state)->toBe('check_failed')->and($dispatch->session)->toBeNull()
            ->and($dispatch->final_check['exit_code'])->toBe(1)
            ->and($f->workspace()->final_check)->toBe($dispatch->final_check)
            ->and($f->workspace()->final_result)->toBeNull();
    } else {
        $dispatch = app(AdvanceTaskWorkspace::class)->handle($f->workspace());
        expect($dispatch->kind)->toBe('final_review')->and($dispatch->session['agentName'])->toBe('task-w42-reviewer')
            ->and($dispatch->prompt)->toContain('Fresh isolated final check', 'Original check 0', 'Original check 1')
            ->not->toContain('TAINTED OLD CHECK', 'OLD PROMPT MUST NEVER REPLAY');
        app(SubmitTaskDispatch::class)->handle($dispatch, ['token' => $dispatch->handoff_token,
            'summary' => 'Fresh integrated review', 'evidence' => 'Actual integrated acceptance evidence.', 'verdict' => $verdict]);
    }
    expect(TaskAgentDispatch::query()->count())->toBe(1)->and(TaskRun::query()->count())->toBe(2)
        ->and(TaskRunReview::query()->count())->toBe(2)
        ->and(Task::query()->findOrFail($f->rootId)->status)->toBe($verdict === 'pass' ? TaskStatus::Completed : TaskStatus::Pending)
        ->and(array_column($f->server->requests(), 'method'))->not->toContain('agent.start', 'tab.create', 'pane.split', 'worktree.open');
})->with(['pass', 'revise', 'check fails']);

it('includes immutable resumption evidence in native inspection', function () {
    $f = $this->fixture;
    $f->recover();
    $result = $f->resume(true);
    config(['commander.projects_path' => $f->directory.'/projects']);
    app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
    expect(Artisan::call('tasks:inspect', ['project' => 'orbit', 'task' => $f->rootId]))->toBe(0);
    $inspection = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($inspection['recovery_resumption']['id'])->toBe($result['audit']['id'])
        ->and($inspection['recovery_resumption']['previous_attention'])->toContain($f->recoveryId)
        ->and($inspection['dispatches'])->toBe([]);
});

it('rejects a competing Delivery on either the source or worktree', function (string $field) {
    $f = $this->fixture;
    $f->recover();
    $project = ProjectOrchestration::query()->create(['manifest_project_id' => 'orbit', 'config' => []]);
    Delivery::query()->create(['project_orchestration_id' => $project->id, 'external_issue_provider' => 'linear',
        'external_issue_id' => 'competing', 'workflow_type' => 'feature', 'workflow_version' => 1,
        'status' => DeliveryStatus::Queued, 'current_phase' => 'planning',
        $field => $field === 'worktree_path' ? $f->worktree : 'ORB-RESUME']);
    expect(fn () => $f->resume(true))->toThrow(LogicException::class, 'active Delivery')
        ->and(TaskRecoveryResumption::query()->count())->toBe(0)->and($f->workspace()->attention)->not->toBeNull();
})->with(['external_issue_key', 'worktree_path']);

it('rejects any existing recovered-workspace dispatch', function () {
    $f = $this->fixture;
    $f->recover();
    $f->workspace()->dispatches()->create(['step_key' => 'old-dispatch', 'kind' => 'final_review', 'round' => 0,
        'token_hash' => hash('sha256', 'old'), 'handoff_token' => 'old', 'prompt' => 'Must not replay']);
    expect(fn () => $f->resume(true))->toThrow(LogicException::class, 'no dispatches');
    Bus::assertNothingDispatched();
});

it('rejects real Git drift and unsupported worktree state without snapshot refs', function (string $case) {
    $f = $this->fixture;
    $f->recover();
    match ($case) {
        'dirty' => File::put($f->worktree.'/feature.txt', 'unreviewed'),
        'untracked' => File::put($f->worktree.'/untracked.txt', 'unreviewed'),
        'extra commit' => $f->git(['commit', '--allow-empty', '-m', 'Unapproved']),
        'hidden index' => $f->git(['update-index', '--assume-unchanged', 'feature.txt']),
        'sparse' => $f->git(['config', 'core.sparseCheckout', 'true']),
        'filter' => $f->git(['config', 'filter.fixture.clean', 'touch '.$f->directory.'/filter-executed']),
        'operation' => File::put($f->git(['rev-parse', '--path-format=absolute', '--git-path', 'CHERRY_PICK_HEAD']), $f->head),
    };
    $refs = $f->git(['show-ref']);
    expect(fn () => $f->resume())->toThrow(LogicException::class)
        ->and($f->git(['show-ref']))->toBe($refs)->and(File::exists($f->directory.'/filter-executed'))->toBeFalse();
    Bus::assertNothingDispatched();
})->with(['dirty', 'untracked', 'extra commit', 'hidden index', 'sparse', 'filter', 'operation']);

it('resolves URL database overrides before opening a resume connection', function (bool $matching) {
    $f = $this->fixture;
    $f->recover();
    config(['database.connections.resume-fixture.url' => 'sqlite:///'.($matching ? $f->database : $f->backup)]);
    if ($matching) {
        expect($f->resume()['mode'])->toBe('preview');
    } else {
        expect(fn () => $f->resume())->toThrow(LogicException::class, 'effective configured SQLite');
        expect($f->server->requests())->toBe([]);
    }
})->with([false, true]);

it('refuses a stale cached default connection before applying to either database', function () {
    $f = $this->fixture;
    $f->recover();
    DB::connectUsing('resume-fixture', ['driver' => 'sqlite', 'database' => $f->backup]);
    $before = hash_file('sha256', $f->backup);
    expect(fn () => $f->resume(true))->toThrow(LogicException::class, 'active runtime connection')
        ->and(hash_file('sha256', $f->backup))->toBe($before)->and($f->server->requests())->toBe([]);
});

it('rejects sidecar-free WAL databases without creating sidecars during preview', function () {
    $f = $this->fixture;
    $f->recover();
    $pdo = new PDO('sqlite:'.$f->database);
    expect($pdo->query('PRAGMA journal_mode=WAL')->fetchColumn())->toBe('wal');
    $pdo = null;
    $before = hash_file('sha256', $f->database);
    expect(File::exists($f->database.'-wal'))->toBeFalse();
    expect(fn () => $f->resume())->toThrow(LogicException::class, 'rollback-journal')
        ->and(hash_file('sha256', $f->database))->toBe($before)
        ->and(File::exists($f->database.'-wal'))->toBeFalse()->and(File::exists($f->database.'-shm'))->toBeFalse();
});

it('never clears a later hold on a matching resumption retry', function () {
    $f = $this->fixture;
    $f->recover();
    $audit = $f->resume(true)['audit'];
    $f->workspace()->update(['attention' => 'New independent hold']);
    expect($f->resume(true)['audit'])->toBe($audit)->and($f->workspace()->attention)->toBe('New independent hold');
    Bus::assertNothingDispatched();
});
