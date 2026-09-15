<?php

use App\Delivery\Contracts\OrbitIssueReader;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\PreparedWorktree;
use App\Delivery\Repositories\OrbitCandidateReceipt;
use App\Models\TaskAgentDispatch;
use App\Models\TaskFinalContinuation;
use App\Models\TaskLanding;
use App\Models\TaskReattempt;
use App\Models\TaskReattemptCheckpoint;
use App\Models\TaskWorkspace;
use App\Projects\SharedKnowledgeProjectRepository;
use App\Tasks\Actions\CreateTask;
use App\Tasks\Enums\TaskKind;
use App\Tasks\Landing\PrepareTaskLanding;
use App\Tasks\Landing\TaskLandingData;
use App\Tasks\Landing\TaskLandingEvidence;
use App\Tasks\Landing\TaskLandingReattemptGit;
use App\Tasks\Landing\TaskLandingRepository;
use App\Tasks\Runtime\AdvanceTaskWorkspace;
use App\Tasks\Runtime\ApplyTaskReattempt;
use App\Tasks\Runtime\ContinueTaskFinal;
use App\Tasks\Runtime\PrepareTaskReattempt;
use App\Tasks\Runtime\StartTaskWorkspace;
use App\Tasks\Runtime\SubmitTaskDispatch;
use App\Tasks\Runtime\TaskAgents;
use App\Tasks\Runtime\TaskReattemptInput;
use App\Tasks\Runtime\TaskRuntimePlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\UsesTaskSharedLocks;

uses(RefreshDatabase::class, UsesTaskSharedLocks::class);

final class LandingReattemptAgents implements TaskAgents
{
    public array $sessions = [];

    public array $prompts = [];

    public function assertSession(TaskWorkspace $workspace, array $session): void
    {
        if (! in_array($session, $this->sessions, true)) {
            throw new LogicException('Unknown disposable agent.');
        }
    }

    public function start(TaskWorkspace $workspace, string $name): array
    {
        if ($workspace->herdr_workspace === null) {
            $workspace->update(['herdr_workspace' => ['workspaceId' => 'fixture-workspace', 'checkoutPath' => $workspace->worktree]]);
        }

        return $this->sessions[$name] = ['workspaceId' => 'fixture-workspace', 'tabId' => 'tab', 'paneId' => 'pane-'.$name,
            'terminalId' => 'terminal-'.$name, 'agentName' => $name, 'agentId' => 'native-'.$name, 'workingDirectory' => $workspace->worktree];
    }

    public function prompt(TaskWorkspace $workspace, array $session, string $prompt): array
    {
        $this->prompts[] = compact('session', 'prompt');

        return $session;
    }
}

final class LandingReattemptRepository implements TaskLandingRepository
{
    public int $publications = 0;

    public ?Closure $duringInspect = null;

    public ?Closure $duringArtifact = null;

    public ?array $published = null;

    public function __construct(public array $observation) {}

    public function inspect(TaskWorkspace $workspace, string $candidate, string $gate): array
    {
        if ($candidate !== $this->observation['candidate'] || $gate !== $this->observation['gate_path']) {
            throw new LogicException('Wrong disposable candidate gate.');
        }
        app(OrbitCandidateReceipt::class)->validate($gate, new PreparedWorktree($workspace->worktree, $candidate),
            $this->observation['tree'], $workspace->worktree, $workspace->repository.'/.git');
        if ($this->duringInspect !== null) {
            ($this->duringInspect)();
        }

        return $this->observation;
    }

    public function artifact(TaskWorkspace $workspace, string $candidate, array $inputs): ?string
    {
        if ($this->duringArtifact !== null) {
            ($this->duringArtifact)();
        }
        if ($this->published !== null && $this->published !== $inputs) {
            throw new LogicException('Changed disposable artifact.');
        }

        return $this->published === null ? null : str_repeat('e', 40);
    }

    public function publish(TaskWorkspace $workspace, string $candidate, array $inputs): void
    {
        $this->publications++;
        $this->published = $inputs;
    }
}

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    $this->landingDirectory = trim(Process::run(['mktemp', '-d', sys_get_temp_dir().'/landing-reattempt-test-XXXXXX'])->throw()->output());
    $this->repository = $this->landingDirectory.'/repository';
    $this->worktree = $this->landingDirectory.'/worktrees/orb-990001';
    File::makeDirectory($this->repository, recursive: true);
    File::makeDirectory(dirname($this->worktree));
    File::makeDirectory($this->landingDirectory.'/projects');
    config(['commander.projects_path' => $this->landingDirectory.'/projects']);
    app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
    landingReattemptGit($this->repository, ['init', '--initial-branch=main']);
    landingReattemptGit($this->repository, ['config', 'user.email', 'landing-reattempt@example.test']);
    landingReattemptGit($this->repository, ['config', 'user.name', 'Landing Reattempt Test']);
    landingReattemptGit($this->repository, ['remote', 'add', 'origin', $this->repository]);
    File::put($this->repository.'/.gitignore', ".loop/\n");
    File::put($this->repository.'/feature.txt', "original\n");
    File::put($this->repository.'/prerequisite.txt', "broken fixture\n");
    landingReattemptGit($this->repository, ['add', '--all']);
    landingReattemptGit($this->repository, ['commit', '-m', 'Original base']);
    $this->originalBase = trim(landingReattemptGit($this->repository, ['rev-parse', 'HEAD']));
    landingReattemptGit($this->repository, ['worktree', 'add', '-b', 'orb-990001', $this->worktree]);
    config(['task-runtime.enabled' => true, 'task-runtime.projects.orbit' => [
        'repository' => $this->repository, 'worktree_root' => dirname($this->worktree), 'socket' => '/unused-fixture.sock',
        'agent_kind' => 'codex', 'agent_arguments' => [], 'flow_version' => 1, 'instructions' => 'Automated-only: database safety and exact candidate gate.',
        'final_command' => [PHP_BINARY, '-r', 'exit(0);'], 'final_timeout' => 5,
    ]]);
    $this->agents = new LandingReattemptAgents;
    app()->instance(TaskAgents::class, $this->agents);
    $this->root = app(CreateTask::class)->handle('orbit', 'Automated safety', 'Deliver database safety.', TaskKind::Group, acceptanceCriteria: 'Safety tests and exact candidate gate.');
    $this->first = app(CreateTask::class)->handle('orbit', 'Safety change', 'Implement the safety change.', parent: $this->root, acceptanceCriteria: 'Focused checks pass.');
    $this->second = app(CreateTask::class)->handle('orbit', 'Follow-on task', 'Integrate the accepted safety change.', parent: $this->root, acceptanceCriteria: 'Accepted chain remains intact.');
    $this->workspace = app(StartTaskWorkspace::class)->handle($this->root, $this->worktree, app(TaskRuntimePlan::class)->hash($this->root), 'ORB-990001', true);
    $this->origin = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
});

afterEach(fn () => File::deleteDirectory($this->landingDirectory));

function landingReattemptGit(string $directory, array $arguments, ?string $input = null): string
{
    if (! str_starts_with($directory, test()->landingDirectory.'/')) {
        throw new LogicException('Git writes are confined to the disposable fixture.');
    }

    return Process::path($directory)->timeout(10)->input($input)->env([
        'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_TERMINAL_PROMPT' => '0', 'GIT_OPTIONAL_LOCKS' => '0',
    ])->run(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'commit.gpgSign=false', ...$arguments])->throw()->output();
}

function landingReattemptSubmit(TaskAgentDispatch $dispatch, ?string $verdict = null): void
{
    $receipt = ['token' => $dispatch->handoff_token, 'summary' => 'Exact fixture handoff.', 'evidence' => 'Disposable automated evidence.'];
    if ($verdict !== null) {
        $receipt['verdict'] = $verdict;
    }
    app(SubmitTaskDispatch::class)->handle($dispatch, $receipt);
}

function landingReattemptLog(string $name): array
{
    $path = test()->landingDirectory.'/'.$name.'.log';

    return ['path' => $path, 'sha256' => hash_file('sha256', $path)];
}

function completeLandingReattempt(bool $reattempt = true, ?string $continuation = null): void
{
    $test = test();
    File::put($test->worktree.'/feature.txt', "preserved task work\n");
    if ($reattempt) {
        landingReattemptSubmit($test->origin, 'blocked');
        landingReattemptGit($test->repository, ['checkout', '-b', 'repair']);
        File::put($test->repository.'/prerequisite.txt', "reviewed fixture repair\n");
        landingReattemptGit($test->repository, ['add', '--all']);
        landingReattemptGit($test->repository, ['commit', '-m', 'Separate reviewed repair']);
        $test->repairCandidate = trim(landingReattemptGit($test->repository, ['rev-parse', 'HEAD']));
        landingReattemptGit($test->repository, ['checkout', 'main']);
        landingReattemptGit($test->repository, ['merge', '--no-ff', '--no-edit', 'repair']);
        $test->verifiedMain = trim(landingReattemptGit($test->repository, ['rev-parse', 'HEAD']));
        foreach (['review', 'main', 'restoration'] as $name) {
            File::put($test->landingDirectory.'/'.$name.'.log', $name.' passed for '.$test->verifiedMain);
        }
        $request = ['reason' => 'Reviewed prerequisite resolved the original block.', 'evidence' => 'Original receipt remains immutable.',
            'prerequisite' => ['source' => 'ORB-990002', 'pull_request' => 'https://github.com/nckrtl/orbit/pull/990002',
                'candidate' => $test->repairCandidate, 'merge' => $test->verifiedMain, 'main' => $test->verifiedMain,
                'review' => landingReattemptLog('review'), 'verification' => ['head' => $test->verifiedMain,
                    'command' => ['composer', 'test:affected'], 'working_directory' => $test->repository,
                    'exit_code' => 0, 'log' => landingReattemptLog('main')]]];
        $prepare = app(PrepareTaskReattempt::class);
        $preview = $prepare->handle($test->workspace->id, $test->origin->id, $test->originalBase, $test->workspace->manifest_hash, $request, true);
        $prepare->handle($test->workspace->id, $test->origin->id, $test->originalBase, $test->workspace->manifest_hash, $request, true, $preview['state_hash'], true);
        $test->checkpoint = TaskReattemptCheckpoint::query()->sole();
        landingReattemptGit($test->worktree, ['read-tree', '--reset', '-u', $test->verifiedMain]);
        landingReattemptGit($test->worktree, ['update-ref', 'HEAD', $test->verifiedMain, $test->originalBase]);
        foreach (['tree' => [], 'index_tree' => ['--cached']] as $kind => $flags) {
            $patch = landingReattemptGit($test->worktree, ['diff', '--binary', '--full-index', $test->originalBase, $test->checkpoint->observation[$kind]]);
            if ($patch !== '') {
                landingReattemptGit($test->worktree, ['apply', '--binary', ...$flags], $patch);
            }
        }
        $request = ['reason' => 'Resume preserved work.', 'evidence' => 'Exact restored source.', 'log' => landingReattemptLog('restoration')];
        $apply = app(ApplyTaskReattempt::class);
        $preview = $apply->handle($test->checkpoint->id, $request, true);
        $apply->handle($test->checkpoint->id, $request, true, $preview['state_hash'], true);
        $test->audit = TaskReattempt::query()->sole();
        $test->successor = app(AdvanceTaskWorkspace::class)->handle($test->workspace);
    } else {
        $test->successor = $test->origin;
    }
    File::put($test->worktree.'/feature.txt', "completed work beyond preserved checkpoint\n");
    foreach ([[$test->successor, 'Accepted first task'], [null, 'Accepted second task']] as [$dispatch, $message]) {
        if ($dispatch === null) {
            $dispatch = app(AdvanceTaskWorkspace::class)->handle($test->workspace);
            File::put($test->worktree.'/second.txt', "Follow-on task\n");
        }
        landingReattemptSubmit($dispatch);
        landingReattemptSubmit(app(AdvanceTaskWorkspace::class)->handle($test->workspace), 'pass');
        $commit = app(AdvanceTaskWorkspace::class)->handle($test->workspace);
        landingReattemptGit($test->worktree, ['add', '--all']);
        landingReattemptGit($test->worktree, ['commit', '-m', $message]);
        landingReattemptSubmit($commit);
    }
    $test->final = app(AdvanceTaskWorkspace::class)->handle($test->workspace);
    if ($continuation !== null) {
        landingReattemptSubmit($test->final, 'blocked');
        $request = ['mode' => $continuation, 'reason' => 'A bounded final continuation.', 'evidence' => 'Retained fixture observation.'];
        if ($continuation === 'append_correction') {
            $request['task'] = ['title' => 'Final correction', 'description' => 'Correct only the existing safety scope.',
                'acceptance_criteria' => 'Safety correction checked.', 'root_criterion' => 'Safety tests and exact candidate gate.'];
        } else {
            $request['environment_repair'] = ['cause' => 'Disposable fixture check.', 'change' => 'Test fixture repaired.', 'verification' => 'Automated fixture passed.'];
        }
        app(ContinueTaskFinal::class)->handle($test->workspace->id, $test->final->id,
            trim(landingReattemptGit($test->worktree, ['rev-parse', 'HEAD'])), app(TaskRuntimePlan::class)->hash($test->root->refresh()), $request, true, true);
        if ($continuation === 'append_correction') {
            $dispatch = app(AdvanceTaskWorkspace::class)->handle($test->workspace);
            File::put($test->worktree.'/correction.txt', "Final scoped correction\n");
            landingReattemptSubmit($dispatch);
            landingReattemptSubmit(app(AdvanceTaskWorkspace::class)->handle($test->workspace), 'pass');
            $commit = app(AdvanceTaskWorkspace::class)->handle($test->workspace);
            landingReattemptGit($test->worktree, ['add', '--all']);
            landingReattemptGit($test->worktree, ['commit', '-m', 'Accepted final correction']);
            landingReattemptSubmit($commit);
        }
        $test->final = app(AdvanceTaskWorkspace::class)->handle($test->workspace);
    }
    landingReattemptSubmit($test->final, 'pass');
    $test->workspace->refresh();
    $test->candidate = trim(landingReattemptGit($test->worktree, ['rev-parse', 'HEAD']));
    $tree = trim(landingReattemptGit($test->worktree, ['rev-parse', 'HEAD^{tree}']));
    $checks = [];
    foreach (['apps/cli', 'apps/docs', 'apps/gateway', 'apps/e2e', 'packages/php-sdk'] as $project) {
        foreach ([['composer', 'validate', '--strict'], ['composer', 'check'], ['composer', 'test:affected']] as $command) {
            $checks[] = ['project' => $project, 'command' => $command, 'exit_code' => 0];
        }
    }
    $gate = $test->repository.'/.git/orbit-checks/'.$test->candidate.'/builder/result.json';
    File::makeDirectory(dirname($gate), recursive: true);
    $contents = TaskLandingData::json(['schema' => 1, 'role' => 'builder', 'candidate' => $test->candidate, 'tree' => $tree,
        'worktree' => $test->worktree, 'passed' => true, 'unchanged' => true, 'checks' => $checks]);
    File::put($gate, $contents);
    $test->packageRepository = new LandingReattemptRepository(['candidate' => $test->candidate, 'tree' => $tree,
        'gate_path' => $gate, 'gate_sha256' => hash('sha256', $contents), 'gate_contents' => $contents,
        'flow_contents' => TaskLandingData::json(['schema' => 1, 'flow' => 'discovery'])]);
    app()->instance(TaskLandingRepository::class, $test->packageRepository);
    $test->issueId = '22222222-2222-4222-8222-222222222222';
    $payload = ['id' => $test->issueId, 'identifier' => 'ORB-990001', 'title' => 'Automated safety', 'description' => 'Deliver database safety.',
        'team' => ['id' => config('commander.hermes.orbit_linear_team_id')], 'state' => ['name' => 'In Review', 'type' => 'started'], 'delegate' => null, 'assignee' => null];
    foreach (['labels', 'attachments', 'children', 'inverseRelations'] as $key) {
        $payload[$key] = ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]];
    }
    $test->issue = new OrbitIssueSnapshot($test->issueId, 'ORB-990001', $payload, str_repeat('d', 64));
    app()->instance(OrbitIssueReader::class, new class($test->issue) implements OrbitIssueReader
    {
        public function __construct(private OrbitIssueSnapshot $issue) {}

        public function read(string $issueId, string $issueKey): OrbitIssueSnapshot
        {
            return $this->issue;
        }
    });
    $test->request = ['candidate' => $test->candidate, 'manifest' => app(TaskRuntimePlan::class)->hash($test->root->refresh()),
        'final_dispatch' => $test->final->id, 'issue_id' => $test->issueId, 'gate_receipt' => $gate,
        'pull_request_body' => 'Automated-only safety and exact candidate gate passed. No live operations.'];
}

it('preserves the exact no-reattempt package fields serialized bytes and input hash', function () {
    completeLandingReattempt(false);
    $workspace = $this->workspace->fresh();
    $root = $this->root->fresh();
    $final = $this->final->fresh();
    $accepted = [];
    foreach ([$this->first, $this->second] as $child) {
        $run = $child->fresh()->acceptedRun()->firstOrFail();
        $review = $run->reviews()->sole();
        $accepted[] = ['task_id' => $child->id, 'run_id' => $run->id, 'base_sha' => $run->base_sha,
            'commit_sha' => $run->commit_sha, 'tree_sha' => $review->tree_sha,
            'handoff' => ['summary' => 'Exact fixture handoff.', 'evidence' => 'Disposable automated evidence.'],
            'review' => ['id' => $review->id, 'round' => 1, 'verdict' => 'pass', 'summary' => 'Exact fixture handoff.', 'evidence' => 'Disposable automated evidence.']];
    }
    $expected = ['schema' => 1, 'authority' => 'Commander Tasks; this is an immutable evidence export, not executable task state.',
        'database' => ['workspace_id' => $workspace->id, 'project_id' => 'orbit', 'source_key' => 'ORB-990001',
            'repository' => $this->repository, 'worktree' => $this->worktree,
            'manifest' => app(TaskRuntimePlan::class)->manifest($root), 'accepted_tasks' => $accepted,
            'final' => ['dispatch_id' => $final->id, 'candidate' => $this->candidate, 'verdict' => 'pass',
                'handoff' => ['summary' => 'Exact fixture handoff.', 'evidence' => 'Disposable automated evidence.']],
            'ownership_hash' => TaskLandingData::hash([$workspace->configuration, $workspace->herdr_workspace, $workspace->reviewer_session,
                $final->toArray(), $workspace->final_check, $root->completed_at->toISOString()])],
        'issue' => ['id' => $this->issueId, 'identifier' => 'ORB-990001', 'team_id' => config('commander.hermes.orbit_linear_team_id'),
            'contract_hash' => str_repeat('d', 64), 'title' => 'Automated safety', 'description' => 'Deliver database safety.', 'labels' => [], 'attachments' => []],
        'repository' => $this->packageRepository->observation, 'evidence_files' => [],
        'retention' => 'Only the explicit evidence_files are embedded. Other paths in handoff prose are references, not archived proof. No cleanup is authorized.'];
    $actual = app(TaskLandingEvidence::class)->capture($workspace, $this->request);
    $preview = app(PrepareTaskLanding::class)->handle($workspace->id, $this->request, true);
    expect($actual)->toBe($expected)->and(TaskLandingData::json($actual))->toBe(TaskLandingData::json($expected))
        ->and($preview['inputs'])->toBe($expected)->and($preview['input_hash'])->toBe(TaskLandingData::hash($expected));
});

it('packages only the proven first-child reattempt chain while retaining admission history', function () {
    completeLandingReattempt();
    $before = [$this->workspace->fresh()->toArray(), $this->origin->fresh()->toArray(), $this->origin->run()->firstOrFail()->toArray()];
    $objects = landingReattemptGit($this->worktree, ['count-objects', '-v']);
    $refs = landingReattemptGit($this->worktree, ['show-ref']);
    $index = hash_file('sha256', trim(landingReattemptGit($this->worktree, ['rev-parse', '--git-path', 'index'])));
    $preview = app(PrepareTaskLanding::class)->handle($this->workspace->id, $this->request, true);
    $database = $preview['inputs']['database'];
    expect($database['reattempt']['admission_base'])->toBe($this->originalBase)
        ->and($database['reattempt']['base_sha'])->toBe($this->verifiedMain)
        ->and($database['accepted_tasks'][0]['base_sha'])->toBe($this->verifiedMain)
        ->and($database['accepted_tasks'][1]['base_sha'])->toBe($database['accepted_tasks'][0]['commit_sha'])
        ->and($this->audit->observation['tree'])->not->toBe($database['accepted_tasks'][0]['tree_sha'])
        ->and($preview['inputs']['reattempt_proof']['accepted_commits'])->toHaveCount(2)
        ->and(TaskLandingData::json($preview))->not->toContain($this->origin->handoff_token, $this->successor->handoff_token, $this->origin->prompt)
        ->and(landingReattemptGit($this->worktree, ['count-objects', '-v']))->toBe($objects)
        ->and(landingReattemptGit($this->worktree, ['show-ref']))->toBe($refs)
        ->and(hash_file('sha256', trim(landingReattemptGit($this->worktree, ['rev-parse', '--git-path', 'index']))))->toBe($index);
    $result = app(PrepareTaskLanding::class)->handle($this->workspace->id, $this->request, true, $preview['proposal_hash'], true);
    expect($result['applied'])->toBeTrue()->and($result['landing']['state'])->toBe('packaged')
        ->and($this->packageRepository->publications)->toBe(1)
        ->and([$this->workspace->fresh()->toArray(), $this->origin->fresh()->toArray(), $this->origin->run()->firstOrFail()->toArray()])->toBe($before);
});

function corruptLandingJson($model, string $column, string $path, mixed $value): void
{
    $json = $model->fresh()->getAttribute($column);
    data_set($json, $path, $value);
    DB::table($model->getTable())->where('id', $model->id)->update([$column => json_encode($json, JSON_THROW_ON_ERROR)]);
}

function rehashLandingCheckpoint(array $request, array $observation): void
{
    $test = test();
    $hash = app(TaskReattemptInput::class)->hash([$test->workspace->id, $test->origin->id, $test->originalBase,
        $test->workspace->manifest_hash, $request, app(TaskReattemptInput::class)->hash($observation)]);
    DB::table('task_reattempt_checkpoints')->where('id', $test->checkpoint->id)->update([
        'request' => json_encode($request, JSON_THROW_ON_ERROR), 'observation' => json_encode($observation, JSON_THROW_ON_ERROR), 'request_hash' => $hash,
    ]);
    foreach (['tree', 'index_tree'] as $kind) {
        landingReattemptGit($test->worktree, ['update-ref', 'refs/commander/task-reattempts/'.$hash.'/'.$kind, $observation[$kind]]);
    }
}

it('refuses missing or drifting first-child reattempt ledger provenance without publishing', function (string $case) {
    completeLandingReattempt();
    $old = $this->origin->run()->firstOrFail();
    $run = $this->successor->run()->firstOrFail();
    $other = $this->second->fresh()->acceptedRun()->firstOrFail();
    $review = $run->reviews()->sole();
    $update = fn ($model, array $fields) => DB::table($model->getTable())->where('id', $model->id)->update($fields);
    match ($case) {
        'missing audits' => (function () {
            DB::table('task_reattempts')->delete();
            DB::table('task_reattempt_checkpoints')->delete();
        })(),
        'missing cutover' => DB::table('task_reattempts')->delete(),
        'missing original id' => corruptLandingJson($this->checkpoint, 'binding', 'run_id', null),
        'checkpoint dispatch' => $update($this->checkpoint, ['task_agent_dispatch_id' => $this->successor->id]),
        'cutover run' => $update($this->audit, ['task_run_id' => $other->id]),
        'cutover dispatch' => $update($this->audit, ['task_agent_dispatch_id' => $this->origin->id]),
        'old base' => $update($old, ['base_sha' => $this->verifiedMain]),
        'old inputs' => corruptLandingJson($old, 'input', 'changed', true),
        'old state' => $update($old, ['status' => 'completed']),
        'old attempt' => $update($old, ['attempt' => 0]),
        'old root' => $update($old, ['root_task_id' => $this->second->id]),
        'old output' => corruptLandingJson($old, 'output', 'result.checkpoint_id', 99999),
        'old reason' => $update($old, ['failure_message' => 'Unrelated failure.']),
        'old review' => $old->reviews()->create(['round' => 1, 'tree_sha' => $review->tree_sha, 'output' => [], 'requested_at' => now()]),
        'new attempt' => $update($run, ['attempt' => 3]),
        'new input' => corruptLandingJson($run, 'input', 'reattempt_checkpoint_id', 99999),
        'new worker' => $update($run, ['worker_ref' => 'different-worker']),
        'new reviewer' => $update($run, ['reviewer_ref' => 'different-reviewer']),
        'new base' => $update($run, ['base_sha' => $this->originalBase]),
        'new task' => $update($run, ['task_id' => $this->second->id]),
        'new active slot' => $update($run, ['active_root_task_id' => $this->root->id]),
        'new unfinished' => $update($run, ['finished_at' => null]),
        'successor blocked' => corruptLandingJson($this->successor, 'result', 'verdict', 'blocked'),
        'successor kind' => $update($this->successor, ['kind' => 'review']),
        'successor round' => $update($this->successor, ['round' => 1]),
        'successor step' => $update($this->successor, ['step_key' => 'unrelated-step']),
        'successor uncertain' => $update($this->successor, ['state' => 'ambiguous']),
        'successor native identity' => corruptLandingJson($this->successor, 'session', 'agentId', 'replacement-native-id'),
        'reused token' => $update($this->successor, ['token_hash' => $this->origin->token_hash, 'handoff_token' => $this->origin->getRawOriginal('handoff_token')]),
        'original receipt' => corruptLandingJson($this->origin, 'result', 'summary', 'Changed original receipt.'),
        'original uncertain' => $update($this->origin, ['state' => 'sent']),
        'original identity' => corruptLandingJson($this->origin, 'session', 'agentId', 'changed-original'),
        'bound inputs' => corruptLandingJson($this->checkpoint, 'binding', 'run.input.changed', true),
        'bound preexisting output' => corruptLandingJson($this->checkpoint, 'binding', 'run.output', ['summary' => 'Earlier output']),
        'bound finished run' => corruptLandingJson($this->checkpoint, 'binding', 'run.finished_at', now()->toISOString()),
        'bound failed run' => corruptLandingJson($this->checkpoint, 'binding', 'run.failure_message', 'Earlier failure'),
        'bound worker' => corruptLandingJson($this->checkpoint, 'binding', 'worker_session.agentId', 'changed-binding'),
        'configuration' => corruptLandingJson($this->workspace, 'configuration', 'instructions', 'Changed scope.'),
        'admission base' => $update($this->workspace, ['base_sha' => $this->verifiedMain]),
        'workspace ownership' => corruptLandingJson($this->workspace, 'herdr_workspace', 'workspaceId', 'changed-workspace'),
        'checkpoint hash' => $update($this->checkpoint, ['request_hash' => str_repeat('c', 64)]),
        'cutover hash' => $update($this->audit, ['request_hash' => str_repeat('c', 64)]),
        'checkpoint request' => corruptLandingJson($this->checkpoint, 'request', 'reason', 'Changed approval.'),
        'cutover request' => corruptLandingJson($this->audit, 'request', 'reason', 'Changed restoration.'),
        'accepted old attempt' => $update($this->first, ['accepted_task_run_id' => $old->id]),
        'review output' => corruptLandingJson($review, 'output', 'summary', 'Changed reviewed output.'),
        'task scope' => $update($this->first, ['description' => 'Unapproved added work.']),
        'later base' => $update($other, ['base_sha' => $this->verifiedMain]),
    };
    expect(fn () => app(PrepareTaskLanding::class)->handle($this->workspace->id, $this->request, true))->toThrow(Exception::class)
        ->and(TaskLanding::query()->count())->toBe(0)->and($this->packageRepository->publications)->toBe(0);
})->with(['missing audits', 'missing cutover', 'missing original id', 'checkpoint dispatch', 'cutover run', 'cutover dispatch',
    'old base', 'old inputs', 'old state', 'old attempt', 'old root', 'old output', 'old reason', 'old review',
    'new attempt', 'new input', 'new worker', 'new reviewer', 'new base', 'new task', 'new active slot', 'new unfinished',
    'successor blocked', 'successor kind', 'successor round', 'successor step', 'successor uncertain', 'successor native identity', 'reused token',
    'original receipt', 'original uncertain', 'original identity', 'bound inputs', 'bound preexisting output', 'bound finished run', 'bound failed run', 'bound worker', 'configuration', 'admission base', 'workspace ownership',
    'checkpoint hash', 'cutover hash', 'checkpoint request', 'cutover request', 'accepted old attempt', 'review output', 'task scope', 'later base']);

it('refuses missing rebound or symbolic retained refs for either preserved tree', function (string $kind, string $mode) {
    completeLandingReattempt();
    $ref = 'refs/commander/task-reattempts/'.$this->checkpoint->request_hash.'/'.$kind;
    if ($mode === 'missing') {
        landingReattemptGit($this->worktree, ['update-ref', '-d', $ref]);
    } elseif ($mode === 'rebound') {
        $wrong = trim(landingReattemptGit($this->worktree, ['rev-parse', $this->candidate.'^{tree}']));
        landingReattemptGit($this->worktree, ['update-ref', $ref, $wrong]);
    } else {
        landingReattemptGit($this->worktree, ['update-ref', 'refs/fixture-retention-alias', $this->checkpoint->observation[$kind]]);
        landingReattemptGit($this->worktree, ['symbolic-ref', $ref, 'refs/fixture-retention-alias']);
    }
    $refs = landingReattemptGit($this->worktree, ['show-ref']);
    expect(fn () => app(PrepareTaskLanding::class)->handle($this->workspace->id, $this->request, true))->toThrow(RuntimeException::class, 'retained checkpoint')
        ->and(landingReattemptGit($this->worktree, ['show-ref']))->toBe($refs)
        ->and(TaskLanding::query()->count())->toBe(0)->and($this->packageRepository->publications)->toBe(0);
})->with(['tree', 'index_tree'])->with(['missing', 'rebound', 'symbolic']);

it('rechecks retained refs and ledger provenance after external observation before applying a package', function (string $case) {
    completeLandingReattempt();
    $preview = app(PrepareTaskLanding::class)->handle($this->workspace->id, $this->request, true);
    $this->packageRepository->duringArtifact = function () use ($case): void {
        match ($case) {
            'tree', 'index_tree' => landingReattemptGit($this->worktree, ['update-ref', '-d', 'refs/commander/task-reattempts/'.$this->checkpoint->request_hash.'/'.$case]),
            'audit' => corruptLandingJson($this->audit, 'request', 'reason', 'Drift after observation.'),
            'old input' => corruptLandingJson($this->origin->run()->firstOrFail(), 'input', 'changed', true),
        };
    };
    expect(fn () => app(PrepareTaskLanding::class)->handle($this->workspace->id, $this->request, true, $preview['proposal_hash'], true))->toThrow(Exception::class)
        ->and(TaskLanding::query()->count())->toBe(0)->and($this->packageRepository->publications)->toBe(0);
})->with(['tree', 'index_tree', 'audit', 'old input']);

it('requires the exact retained review main verification and restoration evidence files', function (string $name, string $mode) {
    completeLandingReattempt();
    if ($mode === 'missing') {
        File::delete($this->landingDirectory.'/'.$name.'.log');
    } else {
        File::put($this->landingDirectory.'/'.$name.'.log', 'Changed evidence bytes.');
    }
    expect(fn () => app(PrepareTaskLanding::class)->handle($this->workspace->id, $this->request, true))->toThrow(InvalidArgumentException::class)
        ->and(TaskLanding::query()->count())->toBe(0)->and($this->packageRepository->publications)->toBe(0);
})->with(['review', 'main', 'restoration'])->with(['missing', 'changed']);

it('rejects a hash-consistent cutover that did not restore either exact preserved tree', function (string $kind) {
    completeLandingReattempt();
    $observation = $this->audit->observation;
    $observation[$kind] = trim(landingReattemptGit($this->worktree, ['rev-parse', $this->candidate.'^{tree}']));
    $input = app(TaskReattemptInput::class);
    DB::table('task_reattempts')->where('id', $this->audit->id)->update(['observation' => json_encode($observation, JSON_THROW_ON_ERROR),
        'request_hash' => $input->hash([$this->checkpoint->id, $this->audit->request, $input->hash($observation)])]);
    expect(fn () => app(PrepareTaskLanding::class)->handle($this->workspace->id, $this->request, true))->toThrow(LogicException::class, 'exact preserved')
        ->and(TaskLanding::query()->count())->toBe(0);
})->with(['tree', 'index_tree']);

it('rejects hash-consistent prerequisite claims with invalid actual merge evidence', function (string $defect) {
    completeLandingReattempt();
    $request = $this->checkpoint->request;
    if ($defect === 'candidate') {
        $request['prerequisite']['candidate'] = $this->originalBase;
    } elseif ($defect === 'single parent') {
        $request['prerequisite']['merge'] = $this->repairCandidate;
    } else {
        $tree = trim(landingReattemptGit($this->worktree, ['rev-parse', ($defect === 'tree' ? $this->candidate : $this->verifiedMain).'^{tree}']));
        $forged = trim(landingReattemptGit($this->worktree, ['commit-tree', $tree, '-p', $this->originalBase, '-p', $this->repairCandidate], "Forged prerequisite merge\n"));
        $request['prerequisite']['merge'] = $forged;
        if ($defect === 'tree') {
            $request['prerequisite']['main'] = $forged;
            $request['prerequisite']['verification']['head'] = $forged;
            $observation = $this->audit->observation;
            $observation['head'] = $forged;
            DB::table('task_runs')->where('id', $this->audit->task_run_id)->update(['base_sha' => $forged]);
            DB::table('task_reattempts')->where('id', $this->audit->id)->update([
                'observation' => json_encode($observation, JSON_THROW_ON_ERROR),
                'request_hash' => app(TaskReattemptInput::class)->hash([$this->checkpoint->id, $this->audit->request, app(TaskReattemptInput::class)->hash($observation)]),
            ]);
        }
    }
    rehashLandingCheckpoint($request, $this->checkpoint->observation);
    $message = match ($defect) {
        'candidate', 'single parent' => 'second parent',
        'tree' => 'conflict-free reviewed merge tree',
        'ancestry' => 'Git observation failed',
    };
    expect(fn () => app(PrepareTaskLanding::class)->handle($this->workspace->id, $this->request, true))->toThrow(RuntimeException::class, $message)
        ->and(TaskLanding::query()->count())->toBe(0)->and($this->packageRepository->publications)->toBe(0);
})->with(['candidate', 'single parent', 'ancestry', 'tree']);

it('rejects hash-consistent changes to the preserved Git assignment', function (string $key) {
    completeLandingReattempt();
    $preserved = $this->checkpoint->observation;
    $preserved[$key] .= '-changed';
    $restored = $this->audit->observation;
    $restored[$key] = $preserved[$key];
    rehashLandingCheckpoint($this->checkpoint->request, $preserved);
    DB::table('task_reattempts')->where('id', $this->audit->id)->update([
        'observation' => json_encode($restored, JSON_THROW_ON_ERROR),
        'request_hash' => app(TaskReattemptInput::class)->hash([$this->checkpoint->id, $this->audit->request, app(TaskReattemptInput::class)->hash($restored)]),
    ]);
    expect(fn () => app(PrepareTaskLanding::class)->handle($this->workspace->id, $this->request, true))->toThrow(LogicException::class, 'Git assignment')
        ->and(TaskLanding::query()->count())->toBe(0);
})->with(['branch', 'git_directory', 'common_directory']);

it('refuses applying the first-base exception to a recovered root', function () {
    completeLandingReattempt();
    DB::table('task_recoveries')->insert(['id' => '33333333-3333-4333-8333-333333333333', 'root_task_id' => $this->root->id,
        'evidence_path' => $this->landingDirectory.'/review.log', 'evidence_sha256' => str_repeat('d', 64),
        'source_path' => $this->landingDirectory.'/source.json', 'source_sha256' => str_repeat('e', 64),
        'backup_path' => $this->landingDirectory.'/backup.sqlite', 'backup_sha256' => str_repeat('f', 64),
        'provenance' => '{}', 'created_at' => now()]);
    expect(fn () => app(PrepareTaskLanding::class)->handle($this->workspace->id, $this->request, true))->toThrow(LogicException::class, 'audited incomplete')
        ->and(TaskLanding::query()->count())->toBe(0);
});

it('requires all prerequisite retained and accepted Git objects without fetching replacements', function (string $kind) {
    completeLandingReattempt();
    $first = $this->first->fresh()->acceptedRun()->firstOrFail();
    $object = match ($kind) {
        'admission' => $this->originalBase,
        'prerequisite candidate' => $this->repairCandidate,
        'prerequisite merge' => $this->verifiedMain,
        'preserved tree' => $this->checkpoint->observation['tree'],
        'staged tree' => $this->checkpoint->observation['index_tree'],
        'first accepted commit' => $first->commit_sha,
        'accepted tree' => $first->reviews()->sole()->tree_sha,
        'final candidate' => $this->candidate,
    };
    $path = $this->repository.'/.git/objects/'.substr($object, 0, 2).'/'.substr($object, 2);
    expect(is_file($path))->toBeTrue();
    File::delete($path);
    $objects = landingReattemptGit($this->worktree, ['count-objects', '-v']);
    expect(fn () => app(PrepareTaskLanding::class)->handle($this->workspace->id, $this->request, true))->toThrow(Exception::class)
        ->and(landingReattemptGit($this->worktree, ['count-objects', '-v']))->toBe($objects)
        ->and(is_file($path))->toBeFalse()->and(TaskLanding::query()->count())->toBe(0);
})->with(['admission', 'prerequisite candidate', 'prerequisite merge', 'preserved tree', 'staged tree', 'first accepted commit', 'accepted tree', 'final candidate']);

it('checks real accepted commit parents and trees instead of trusting ledger base links', function (int $position, string $defect) {
    completeLandingReattempt();
    $database = app(TaskLandingEvidence::class)->database($this->workspace->fresh(), $this->request);
    $accepted = $database['accepted_tasks'];
    $tree = $accepted[$position]['tree_sha'];
    $parents = $defect === 'merge' ? [$accepted[$position]['base_sha'], $this->originalBase] : [$this->originalBase];
    if ($defect === 'tree') {
        $tree = $this->checkpoint->observation['tree'];
        $parents = [$accepted[$position]['base_sha']];
    }
    $arguments = ['commit-tree', $tree];
    foreach ($parents as $parent) {
        $arguments = [...$arguments, '-p', $parent];
    }
    $forged = trim(landingReattemptGit($this->worktree, $arguments, "Forged disposable accepted commit\n"));
    $accepted[$position]['commit_sha'] = $forged;
    if ($position === 0) {
        $accepted[1]['base_sha'] = $forged;
    }
    expect(fn () => app(TaskLandingReattemptGit::class)->proof($this->workspace, $database['reattempt'], $accepted))->toThrow(LogicException::class, 'sole parent');
})->with([0, 1])->with(['wrong parent', 'merge', 'tree']);

it('permits ordinary metadata drift but not a replacement worker or a current-main base override', function () {
    completeLandingReattempt();
    corruptLandingJson($this->successor, 'session', 'agentStatus', 'done');
    corruptLandingJson($this->successor, 'session', 'sequence', 123);
    File::put($this->repository.'/later-main.txt', 'Unrelated later main work.');
    landingReattemptGit($this->repository, ['add', '--all']);
    landingReattemptGit($this->repository, ['commit', '-m', 'Later main work']);
    $preview = app(PrepareTaskLanding::class)->handle($this->workspace->id, $this->request, true);
    expect($preview['inputs']['database']['reattempt']['base_sha'])->toBe($this->verifiedMain)
        ->and($preview['inputs']['database']['reattempt']['replacement']['worker_identity']['agentId'])->toBe($this->origin->session['agentId']);
});

it('preserves audited final-continuation manifests after the first-base transition', function (string $mode) {
    completeLandingReattempt(continuation: $mode);
    $preview = app(PrepareTaskLanding::class)->handle($this->workspace->id, $this->request, true);
    expect($preview['inputs']['database']['reattempt']['original_manifest'])->toBe($this->workspace->manifest_hash)
        ->and($preview['inputs']['database']['reattempt']['manifest_history'])->toHaveCount(1)
        ->and($preview['inputs']['reattempt_proof']['accepted_commits'])->toHaveCount($mode === 'append_correction' ? 3 : 2);
})->with(['append_correction', 'retry_final_checks']);

it('refuses missing or changed final-continuation provenance after a reattempt', function (string $mode, string $defect) {
    completeLandingReattempt(continuation: $mode);
    $audit = TaskFinalContinuation::query()->sole();
    match ($defect) {
        'missing audit' => DB::table('task_final_continuations')->where('id', $audit->id)->delete(),
        'round gap' => DB::table('task_final_continuations')->where('id', $audit->id)->update(['final_round' => 2]),
        'request drift' => corruptLandingJson($audit, 'request', 'reason', 'Unapproved changed continuation.'),
        'previous manifest drift' => DB::table('task_final_continuations')->where('id', $audit->id)->update(['previous_manifest_hash' => str_repeat('a', 64)]),
    };
    expect(fn () => app(PrepareTaskLanding::class)->handle($this->workspace->id, $this->request, true))->toThrow(LogicException::class)
        ->and(TaskLanding::query()->count())->toBe(0)->and($this->packageRepository->publications)->toBe(0);
})->with(['append_correction', 'retry_final_checks'])->with(['missing audit', 'round gap', 'request drift', 'previous manifest drift']);
