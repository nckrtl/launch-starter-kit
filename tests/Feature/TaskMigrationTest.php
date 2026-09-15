<?php

use App\Delivery\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\ProjectOrchestration;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\TaskRunReview;
use App\Models\TaskWorkspace;
use App\Projects\SharedKnowledgeProjectRepository;
use App\Tasks\Actions\AcceptTaskRun;
use App\Tasks\Actions\AddTaskDependency;
use App\Tasks\Actions\CreateTask;
use App\Tasks\Actions\MarkTaskRunReadyForReview;
use App\Tasks\Actions\RecordTaskRunReview;
use App\Tasks\Actions\StartTaskRun;
use App\Tasks\Enums\TaskKind;
use App\Tasks\Enums\TaskReviewVerdict;
use App\Tasks\TaskCommit;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\Support\UsesTaskSharedLocks;

uses(DatabaseMigrations::class, UsesTaskSharedLocks::class);

beforeEach(function () {
    $this->projectsPath = storage_path('framework/testing/task-migrations-'.bin2hex(random_bytes(4)));
    File::makeDirectory($this->projectsPath, 0755, true);
    config()->set('commander.projects_path', $this->projectsPath);
    app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
    $this->group = app(CreateTask::class)->handle('orbit', 'Group', kind: TaskKind::Group);
    $this->dependency = app(CreateTask::class)->handle('orbit', 'Plan', parent: $this->group);
    $this->child = app(CreateTask::class)->handle('orbit', 'Implement', parent: $this->group);
    app(AddTaskDependency::class)->handle($this->child, $this->dependency);
    $this->run = app(StartTaskRun::class)->handle($this->dependency, 'first', 'first-worker', 'test-reviewer', baseSha: str_repeat('a', 40));
    $review = app(MarkTaskRunReadyForReview::class)->handle($this->run, 'first-worker', 1, ['summary' => 'First'], str_repeat('d', 40));
    app(RecordTaskRunReview::class)->handle($review, 'test-reviewer', TaskReviewVerdict::Revise, 'Needs changes.', 'test://review/1');
    $review = app(MarkTaskRunReadyForReview::class)->handle($this->run, 'first-worker', 2, ['summary' => 'Done'], str_repeat('b', 40));
    app(RecordTaskRunReview::class)->handle($review, 'test-reviewer', TaskReviewVerdict::Pass, 'Verified.', 'test://review/2');
    app(AcceptTaskRun::class)->handle($review, new TaskCommit(str_repeat('c', 40), str_repeat('b', 40), [str_repeat('a', 40)]));
    app(StartTaskRun::class)->handle($this->child, 'implement', 'second-worker', 'test-reviewer', baseSha: str_repeat('c', 40));
});

afterEach(fn () => File::deleteDirectory($this->projectsPath));

it('reverses and reapplies the task migrations without changing existing delivery records', function () {
    $orchestration = ProjectOrchestration::query()->create(['manifest_project_id' => 'orbit', 'config' => []]);
    $delivery = Delivery::query()->create([
        'project_orchestration_id' => $orchestration->id,
        'external_issue_provider' => 'linear',
        'external_issue_id' => 'migration-sentinel',
        'workflow_type' => 'migration-sentinel',
        'workflow_version' => 1,
        'status' => DeliveryStatus::Queued,
        'current_phase' => 'planning',
    ]);
    $migration = require database_path('migrations/2026_09_12_065336_create_task_tables.php');
    $reviewMigration = require database_path('migrations/2026_09_12_092418_add_task_review_gate.php');
    $briefMigration = require database_path('migrations/2026_09_12_115402_add_task_brief_fields_to_tasks.php');
    $runtimeMigration = require database_path('migrations/2026_09_12_151853_create_task_runtime_tables.php');
    $continuationMigration = require database_path('migrations/2026_09_13_000000_add_task_final_continuations.php');

    $continuationMigration->down();
    $runtimeMigration->down();
    $briefMigration->down();
    $reviewMigration->down();
    $migration->down();

    foreach (['tasks', 'task_runs', 'task_dependencies', 'task_run_reviews'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }

    foreach (['deliveries', 'phase_runs', 'agent_dispatches', 'receipts', 'external_events', 'maintenance_runs'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }

    $this->assertModelExists($delivery);
    $this->assertModelExists($orchestration);
    $migration->up();
    $reviewMigration->up();
    $briefMigration->up();
    $runtimeMigration->up();
    $continuationMigration->up();

    expect(Task::query()->count())->toBe(0)
        ->and(TaskRun::query()->count())->toBe(0)
        ->and($delivery->fresh()->external_issue_id)->toBe('migration-sentinel')
        ->and($delivery->fresh()->status)->toBe(DeliveryStatus::Queued)
        ->and(DB::select('PRAGMA foreign_key_check'))->toBe([])
        ->and((int) DB::scalar('PRAGMA foreign_keys'))->toBe(1);
    $task = app(CreateTask::class)->handle('orbit', 'Task after migration');

    expect(app(StartTaskRun::class)->handle($task, 'after-migration', 'new-worker', 'test-reviewer')->attempt)->toBe(1);
});

it('reverses populated runtime tables without changing task briefs or acceptance history', function () {
    $workspace = TaskWorkspace::query()->create([
        'root_task_id' => $this->group->id, 'project_id' => 'orbit', 'source_key' => 'migration-runtime',
        'repository' => '/tmp/repository', 'worktree' => '/tmp/worktree', 'base_sha' => str_repeat('a', 40),
        'manifest_hash' => str_repeat('b', 64), 'configuration' => ['flow_version' => 1],
    ]);
    $workspace->dispatches()->create([
        'task_run_id' => $this->run->id, 'step_key' => 'test:implement:0', 'kind' => 'implement',
        'token_hash' => str_repeat('c', 64), 'handoff_token' => 'migration-token', 'prompt' => 'Test',
        'execution_key' => 'migration-job',
    ]);
    $migration = require database_path('migrations/2026_09_12_151853_create_task_runtime_tables.php');
    $continuationMigration = require database_path('migrations/2026_09_13_000000_add_task_final_continuations.php');
    $continuationMigration->down();
    $migration->down();

    expect(Schema::hasTable('task_workspaces'))->toBeFalse()
        ->and(Schema::hasTable('task_agent_dispatches'))->toBeFalse()
        ->and($this->dependency->fresh()->accepted_task_run_id)->toBe($this->run->id)
        ->and($this->run->reviews()->count())->toBe(2)
        ->and(DB::select('PRAGMA foreign_key_check'))->toBe([]);
    $migration->up();
    $continuationMigration->up();

    expect(TaskWorkspace::query()->count())->toBe(0)
        ->and(Schema::hasColumn('task_agent_dispatches', 'execution_key'))->toBeTrue()
        ->and(TaskRun::query()->count())->toBe(2)
        ->and(DB::select('PRAGMA foreign_key_check'))->toBe([]);
});

it('adds and removes Orbit Herdr placement without changing task workspace history', function () {
    $workspace = TaskWorkspace::query()->create([
        'root_task_id' => $this->group->id, 'project_id' => 'orbit', 'source_key' => 'migration-herdr-placement',
        'repository' => '/tmp/repository', 'worktree' => '/tmp/herdr-placement-worktree', 'base_sha' => str_repeat('a', 40),
        'manifest_hash' => str_repeat('b', 64), 'configuration' => ['flow_version' => 1],
        'orbit_node_id' => 9, 'orbit_herdr_session_id' => 41, 'orbit_herdr_session' => 'commander-tasks',
        'orbit_herdr_observer_origin' => 'wss://commander-tasks.herdr.beast.test',
    ]);
    $migration = require database_path('migrations/2026_09_14_120000_add_orbit_herdr_placement_to_task_workspaces.php');

    $migration->down();

    expect(Schema::hasColumn('task_workspaces', 'orbit_node_id'))->toBeFalse()
        ->and(TaskWorkspace::query()->whereKey($workspace->id)->exists())->toBeTrue();

    $migration->up();

    $workspace->refresh();
    expect(Schema::hasColumn('task_workspaces', 'orbit_herdr_session_id'))->toBeTrue()
        ->and($workspace->orbit_node_id)->toBeNull()
        ->and($workspace->orbit_herdr_session_id)->toBeNull()
        ->and(DB::select('PRAGMA foreign_key_check'))->toBe([]);
});

it('reverses and reapplies the review schema with populated tasks and acceptance references', function () {
    $migration = require database_path('migrations/2026_09_12_092418_add_task_review_gate.php');
    $migration->down();

    expect(Schema::hasTable('task_run_reviews'))->toBeFalse()
        ->and(Schema::hasColumn('task_runs', 'commit_sha'))->toBeFalse()
        ->and(Schema::hasColumn('tasks', 'accepted_task_run_id'))->toBeFalse()
        ->and($this->run->fresh()->output)->toBe(['summary' => 'Done'])
        ->and($this->child->dependencies()->sole()->is($this->dependency))->toBeTrue()
        ->and(DB::select('PRAGMA foreign_key_check'))->toBe([])
        ->and((int) DB::scalar('PRAGMA foreign_keys'))->toBe(1);
    $this->assertModelExists($this->group);
    $migration->up();

    expect(TaskRunReview::query()->count())->toBe(0)
        ->and($this->run->fresh()->commit_sha)->toBeNull()
        ->and($this->dependency->fresh()->accepted_task_run_id)->toBeNull()
        ->and($this->child->runs()->count())->toBe(1)
        ->and(DB::select('PRAGMA foreign_key_check'))->toBe([])
        ->and((int) DB::scalar('PRAGMA foreign_keys'))->toBe(1);
});

it('adds and removes preparation fields without changing task links or run history', function () {
    $migration = require database_path('migrations/2026_09_12_115402_add_task_brief_fields_to_tasks.php');
    DB::table('tasks')->where('id', $this->group->id)->update(['acceptance_criteria' => 'Feature checks pass', 'creation_key' => 'published-feature']);
    $runCount = TaskRun::query()->count();
    $reviewCount = TaskRunReview::query()->count();

    $migration->down();
    expect(Schema::hasColumn('tasks', 'acceptance_criteria'))->toBeFalse()
        ->and(Schema::hasColumn('tasks', 'creation_key'))->toBeFalse()
        ->and($this->child->dependencies()->sole()->is($this->dependency))->toBeTrue()
        ->and(TaskRun::query()->count())->toBe($runCount)
        ->and(TaskRunReview::query()->count())->toBe($reviewCount);

    $migration->up();
    expect($this->group->fresh()->acceptance_criteria)->toBe('')
        ->and($this->group->fresh()->creation_key)->toBeNull()
        ->and($this->dependency->fresh()->accepted_task_run_id)->toBe($this->run->id)
        ->and(DB::select('PRAGMA foreign_key_check'))->toBe([]);
});
