<?php

use App\Delivery\Contracts\OrbitIssueReader;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Models\Task;
use App\Models\TaskFinalContinuation;
use App\Models\TaskRun;
use App\Models\TaskWorkspace;
use App\Projects\SharedKnowledgeProjectRepository;
use App\Tasks\Actions\CreateTask;
use App\Tasks\Enums\TaskKind;
use App\Tasks\Enums\TaskRunStatus;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\Landing\TaskLandingData;
use App\Tasks\Landing\TaskLandingEvidence;
use App\Tasks\Landing\TaskLandingRepository;
use App\Tasks\Orbit\Proof\OrbitTaskProofEvidence;
use App\Tasks\Runtime\TaskRuntimePlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process as NativeProcess;
use Tests\Support\UsesTaskSharedLocks;

uses(RefreshDatabase::class, UsesTaskSharedLocks::class);

function exportCompatibilityGit(string $path, array $arguments): string
{
    return trim((new NativeProcess(['git', '-C', $path, ...$arguments], env: [
        'GIT_AUTHOR_DATE' => '2026-09-14T08:00:00+00:00',
        'GIT_COMMITTER_DATE' => '2026-09-14T08:00:00+00:00',
    ]))->mustRun()->getOutput());
}

function exportCompatibilityAccept(Task $task, string $base, string $commit, string $tree): array
{
    $run = TaskRun::query()->create(['task_id' => $task->id, 'root_task_id' => $task->parent_id,
        'attempt' => 1, 'idempotency_key' => 'accepted-'.$task->id, 'worker_ref' => 'worker-'.$task->id,
        'reviewer_ref' => 'reviewer', 'base_sha' => $base, 'commit_sha' => $commit,
        'status' => TaskRunStatus::Completed, 'input' => ['private_input' => 'must-not-be-exported'],
        'output' => ['summary' => 'Accepted task '.$task->id, 'evidence' => 'Focused checks passed.'],
        'started_at' => now(), 'finished_at' => now()]);
    $review = $run->reviews()->create(['round' => 1, 'tree_sha' => $tree,
        'output' => $run->output, 'requested_at' => now(), 'verdict' => 'pass',
        'summary' => 'Reviewed task '.$task->id, 'evidence_ref' => 'Exact tree verified.', 'reviewed_at' => now()]);
    $task->update(['status' => TaskStatus::Completed, 'completed_at' => now(), 'accepted_task_run_id' => $run->id]);

    return ['task_id' => $task->id, 'run_id' => $run->id, 'base_sha' => $base,
        'commit_sha' => $commit, 'tree_sha' => $tree,
        'handoff' => ['summary' => 'Accepted task '.$task->id, 'evidence' => 'Focused checks passed.'],
        'review' => ['id' => $review->id, 'round' => 1, 'verdict' => 'pass',
            'summary' => 'Reviewed task '.$task->id, 'evidence' => 'Exact tree verified.']];
}

function exportCompatibilityFixture(string $mode, bool $proof): array
{
    $test = test();
    $repository = $test->exportDirectory.'/repository';
    $worktree = $test->exportDirectory.'/worktrees/orb-91';
    File::makeDirectory($repository, 0700, true);
    exportCompatibilityGit($repository, ['init', '--initial-branch=main']);
    exportCompatibilityGit($repository, ['config', 'user.name', 'Export Test']);
    exportCompatibilityGit($repository, ['config', 'user.email', 'export@example.test']);
    exportCompatibilityGit($repository, ['config', 'commit.gpgsign', 'false']);
    exportCompatibilityGit($repository, ['config', 'core.hooksPath', '/dev/null']);
    File::put($repository.'/.gitignore', ".loop/\n");
    File::put($repository.'/feature.txt', "Initial feature\n");
    exportCompatibilityGit($repository, ['add', '--all']);
    exportCompatibilityGit($repository, ['commit', '-m', 'Initial']);
    $base = exportCompatibilityGit($repository, ['rev-parse', 'HEAD']);
    exportCompatibilityGit($repository, ['remote', 'add', 'origin', 'https://github.com/nckrtl/orbit.git']);
    exportCompatibilityGit($repository, ['worktree', 'add', '-b', 'orb-91', $worktree]);
    File::put($worktree.'/feature.txt', "Implemented feature\n");
    exportCompatibilityGit($worktree, ['commit', '-am', 'Reviewed feature']);
    $candidate = exportCompatibilityGit($worktree, ['rev-parse', 'HEAD']);
    $tree = exportCompatibilityGit($worktree, ['rev-parse', 'HEAD^{tree}']);

    $root = app(CreateTask::class)->handle('orbit', 'Legacy feature', 'Deliver the scoped feature.',
        TaskKind::Group, acceptanceCriteria: 'The feature passes its focused checks.');
    $child = app(CreateTask::class)->handle('orbit', 'Implement feature', 'One focused change.',
        parent: $root, acceptanceCriteria: 'Focused checks pass.');
    $originalHash = app(TaskRuntimePlan::class)->hash($root);
    $session = ['workspaceId' => 'w1', 'tabId' => 't1', 'paneId' => 'p1', 'terminalId' => 'term1',
        'agentName' => 'reviewer', 'agentId' => '77777777-7777-4777-8777-777777777777', 'workingDirectory' => $worktree];
    $configuration = ['repository' => $repository, 'worktree_root' => dirname($worktree), 'flow_version' => 1,
        'socket' => '/unused-export-test.sock', 'agent_kind' => 'codex', 'instructions' => 'Private runtime instructions.',
        ...($proof ? ['orbit_profile' => ['schema' => 1, 'flow' => 'proof', 'snapshot_replacement' => true]] : [])];
    $workspace = TaskWorkspace::query()->create(['root_task_id' => $root->id, 'project_id' => 'orbit',
        'source_key' => 'ORB-91', 'repository' => $repository, 'worktree' => $worktree, 'base_sha' => $base,
        'manifest_hash' => $originalHash, 'configuration' => $configuration,
        'herdr_workspace' => ['workspaceId' => 'w1', 'paneId' => 'p1'], 'reviewer_session' => $session]);
    $accepted = [exportCompatibilityAccept($child, $base, $candidate, $tree)];

    if ($mode !== 'none') {
        $held = $workspace->dispatches()->create(['step_key' => 'final:0', 'kind' => 'final_review',
            'state' => 'check_failed', 'final_check_version' => 1,
            'final_check' => ['sha' => $candidate, 'manifest_hash' => $originalHash,
                'candidate_unchanged' => true, 'exit_code' => 1],
            'error' => 'Final checks failed.', 'token_hash' => hash('sha256', 'private-held-token'),
            'handoff_token' => 'private-held-token', 'prompt' => 'Private held final prompt.']);
        $heldHead = $candidate;
        $correction = null;
        $request = ['mode' => $mode, 'reason' => 'Resolve the final check failure.', 'evidence' => 'Retained failed check.'];
        if ($mode === 'append_correction') {
            $brief = ['title' => 'Correct final check', 'description' => 'Fix the focused regression.',
                'acceptance_criteria' => 'Focused checks pass.', 'root_criterion' => 'The feature passes its focused checks.'];
            $request['task'] = $brief;
            $correction = Task::query()->create(['project_id' => 'orbit', 'parent_id' => $root->id,
                'kind' => TaskKind::Executable, ...array_diff_key($brief, ['root_criterion' => true])]);
            $correction->dependencies()->attach($child->id);
            File::put($worktree.'/feature.txt', "Implemented and corrected feature\n");
            exportCompatibilityGit($worktree, ['commit', '-am', 'Reviewed correction']);
            $candidate = exportCompatibilityGit($worktree, ['rev-parse', 'HEAD']);
            $tree = exportCompatibilityGit($worktree, ['rev-parse', 'HEAD^{tree}']);
            $accepted[] = exportCompatibilityAccept($correction, $heldHead, $candidate, $tree);
        } else {
            $request['environment_repair'] = ['cause' => 'Disposable fixture unavailable.',
                'change' => 'Restored fixture.', 'verification' => 'Fixture responds.'];
        }
        TaskFinalContinuation::query()->create(['task_workspace_id' => $workspace->id,
            'task_agent_dispatch_id' => $held->id, 'task_id' => $correction?->id, 'mode' => $mode,
            'request_hash' => hash('sha256', json_encode([$workspace->id, $held->id, $heldHead, $originalHash, $request], JSON_THROW_ON_ERROR)),
            'head' => $heldHead, 'previous_manifest_hash' => $originalHash,
            'manifest_hash' => app(TaskRuntimePlan::class)->hash($root), 'final_round' => 1,
            'previous_attention' => 'Final checks failed.', 'previous_result' => null, 'request' => $request, 'created_at' => now()]);
    }

    $manifest = app(TaskRuntimePlan::class)->manifest($root);
    $check = ['sha' => $candidate, 'manifest_hash' => app(TaskRuntimePlan::class)->hash($root),
        'candidate_unchanged' => true, 'command' => ['composer', 'check'], 'exit_code' => 0,
        'output' => 'passed', 'error_output' => ''];
    $result = ['verdict' => 'pass', 'summary' => 'Integrated feature passed.', 'evidence' => 'All criteria verified.'];
    $final = $workspace->dispatches()->create(['step_key' => $mode === 'none' ? 'final:0' : 'final:1',
        'kind' => 'final_review', 'round' => $mode === 'none' ? 0 : 1, 'state' => $proof ? 'sending' : 'acknowledged',
        'final_check_version' => $mode === 'none' && ! $proof ? null : 1,
        'final_check' => $proof || $mode === 'none' ? null : $check,
        'token_hash' => hash('sha256', 'private-final-token'), 'handoff_token' => 'private-final-token',
        'prompt' => 'Private final review prompt.', 'session' => $proof ? null : $session, 'result' => $proof ? null : $result]);
    if (! $proof) {
        $root->update(['status' => TaskStatus::Completed, 'completed_at' => now()]);
        $workspace->update(['final_check' => $check, 'final_result' => $result]);
    }
    $workspace->refresh();
    $final->refresh();
    $flow = TaskLandingData::json(['schema' => 1, 'flow' => $proof ? 'proof' : 'discovery']);
    File::makeDirectory($worktree.'/.loop/proof', 0700, true);
    File::put($worktree.'/.loop/flow.json', $flow);
    $contract = TaskLandingData::json(['snapshot_replacement' => true, 'inputs' => ['.loop/proof/request.json']]);
    $fixture = "{\"objective\":\"Verify the accepted feature\"}\n";
    File::put($worktree.'/.loop/proof/ORB-91.json', $contract);
    File::put($worktree.'/.loop/proof/request.json', $fixture);

    return compact('workspace', 'root', 'manifest', 'accepted', 'candidate', 'tree', 'final', 'check', 'result', 'flow', 'contract', 'fixture');
}

beforeEach(function () {
    $this->freezeTime();
    Http::preventStrayRequests();
    Queue::fake();
    $this->exportDirectory = storage_path('framework/testing/export-compatibility-'.bin2hex(random_bytes(8)));
    File::makeDirectory($this->exportDirectory.'/projects', 0700, true);
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'commander.projects_path' => $this->exportDirectory.'/projects',
        'commander.hermes.orbit_linear_team_id' => '11111111-1111-4111-8111-111111111111']);
    app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
});

afterEach(fn () => File::deleteDirectory($this->exportDirectory));

it('keeps the complete pre-integration landing export bytes including its ownership hash', function (string $mode) {
    Process::preventStrayProcesses();
    $data = exportCompatibilityFixture($mode, false);
    extract($data);
    $repository = ['candidate' => $candidate, 'tree' => $tree, 'gate_path' => '/retained/test-gate.json',
        'gate_sha256' => str_repeat('c', 64), 'gate_contents' => '{"passed":true}', 'flow_contents' => $flow];
    app()->instance(TaskLandingRepository::class, new class($repository) implements TaskLandingRepository
    {
        public function __construct(private array $evidence) {}

        public function inspect(TaskWorkspace $workspace, string $candidate, string $gate): array
        {
            return $this->evidence;
        }

        public function artifact(TaskWorkspace $workspace, string $candidate, array $inputs): ?string
        {
            throw new LogicException('The legacy discovery capture must not access artifacts.');
        }

        public function publish(TaskWorkspace $workspace, string $candidate, array $inputs): void
        {
            throw new LogicException('Evidence capture must not publish.');
        }
    });
    $issueId = '22222222-2222-4222-8222-222222222222';
    $team = config('commander.hermes.orbit_linear_team_id');
    $issue = new OrbitIssueSnapshot($issueId, 'ORB-91', ['id' => $issueId, 'identifier' => 'ORB-91',
        'title' => 'Legacy feature', 'description' => 'Deliver the scoped feature.', 'team' => ['id' => $team],
        'state' => ['name' => 'In Review', 'type' => 'started'], 'assignee' => null, 'delegate' => null,
        ...array_fill_keys(['children', 'inverseRelations', 'attachments'], ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]]),
        'labels' => ['nodes' => [['name' => 'docs'], ['name' => 'controller:tasks']], 'pageInfo' => ['hasNextPage' => false]]], str_repeat('d', 64));
    app()->instance(OrbitIssueReader::class, new class($issue) implements OrbitIssueReader
    {
        public function __construct(private OrbitIssueSnapshot $issue) {}

        public function read(string $issueId, string $issueKey): OrbitIssueSnapshot
        {
            return $this->issue;
        }
    });
    $request = ['candidate' => $candidate, 'manifest' => $check['manifest_hash'], 'final_dispatch' => $final->id,
        'issue_id' => $issueId, 'gate_receipt' => '/retained/test-gate.json', 'pull_request_body' => 'Retained feature acceptance.'];
    $legacyFinal = array_diff_key($final->toArray(), ['final_preflight_version' => true, 'final_preflight' => true]);
    $expected = ['schema' => 1,
        'authority' => 'Commander Tasks; this is an immutable evidence export, not executable task state.',
        'database' => ['workspace_id' => $workspace->id, 'project_id' => 'orbit', 'source_key' => 'ORB-91',
            'repository' => $workspace->repository, 'worktree' => $workspace->worktree, 'manifest' => $manifest,
            'accepted_tasks' => $accepted,
            'final' => ['dispatch_id' => $final->id, 'candidate' => $candidate, 'verdict' => 'pass',
                'handoff' => ['summary' => 'Integrated feature passed.', 'evidence' => 'All criteria verified.']],
            'ownership_hash' => TaskLandingData::hash([$workspace->configuration, $workspace->herdr_workspace,
                $workspace->reviewer_session, $legacyFinal, $check, $root->completed_at->toISOString()])],
        'issue' => ['id' => $issueId, 'identifier' => 'ORB-91', 'team_id' => $team, 'contract_hash' => str_repeat('d', 64),
            'title' => 'Legacy feature', 'description' => 'Deliver the scoped feature.',
            'labels' => ['controller:tasks', 'docs'], 'attachments' => []],
        'repository' => $repository, 'evidence_files' => [],
        'retention' => 'Only the explicit evidence_files are embedded. Other paths in handoff prose are references, not archived proof. No cleanup is authorized.'];

    $actual = app(TaskLandingEvidence::class)->capture($workspace, $request);

    expect(TaskLandingData::json($actual))->toBe(TaskLandingData::json($expected))
        ->and(TaskLandingData::hash($actual))->toBe(TaskLandingData::hash($expected));
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(['none', 'retry_final_checks', 'append_correction']);

it('keeps the complete pre-integration proof artifact bytes with ordinary continuation history', function (string $mode) {
    $data = exportCompatibilityFixture($mode, true);
    extract($data);
    $expected = ['schema' => 2,
        'authority' => 'Commander Tasks; immutable pre-proof evidence export, not executable task state.',
        'database' => ['workspace_id' => $workspace->id, 'project_id' => 'orbit', 'source_key' => 'ORB-91',
            'manifest' => $manifest, 'accepted_tasks' => $accepted, 'builder_gate' => $check, 'final_dispatch_id' => $final->id],
        'repository' => ['candidate' => $candidate, 'tree' => $tree, 'flow_contents' => $flow],
        'proof_contract' => [
            ['path' => '.loop/proof/ORB-91.json', 'mode' => '100644', 'sha256' => hash('sha256', $contract), 'contents' => $contract],
            ['path' => '.loop/proof/request.json', 'mode' => '100644', 'sha256' => hash('sha256', $fixture), 'contents' => $fixture],
        ],
        'retention' => 'Final verdict, native proof results, capture, and interactive review are intentionally outside this immutable artifact.'];

    $actual = app(OrbitTaskProofEvidence::class)->inputs($workspace, $check);

    expect(TaskLandingData::json($actual))->toBe(TaskLandingData::json($expected))
        ->and(TaskLandingData::hash($actual))->toBe(TaskLandingData::hash($expected));
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(['none', 'retry_final_checks', 'append_correction']);
