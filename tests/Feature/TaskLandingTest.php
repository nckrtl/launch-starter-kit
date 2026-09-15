<?php

use App\Delivery\Contracts\OrbitIssueReader;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\PreparedWorktree;
use App\Delivery\Exceptions\OrbitIssueProviderFailed;
use App\Delivery\Exceptions\OrbitRepositoryFailed;
use App\Delivery\IssueProviders\SshOrbitIssueProvider;
use App\Delivery\Repositories\OrbitCandidateReceipt;
use App\Models\TaskAgentDispatch;
use App\Models\TaskLanding;
use App\Models\TaskRun;
use App\Models\TaskWorkspace;
use App\Projects\SharedKnowledgeProjectRepository;
use App\Tasks\Actions\CreateTask;
use App\Tasks\Enums\TaskKind;
use App\Tasks\Enums\TaskRunStatus;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\Landing\AmendTaskLanding;
use App\Tasks\Landing\HerdrTaskLandingReviewer;
use App\Tasks\Landing\PrepareTaskLanding;
use App\Tasks\Landing\ReviewTaskLanding;
use App\Tasks\Landing\TaskLandingData;
use App\Tasks\Landing\TaskLandingEvidence;
use App\Tasks\Landing\TaskLandingRepository;
use App\Tasks\Landing\TaskLandingReviewer;
use App\Tasks\Landing\TaskLandingReviewTransport;
use App\Tasks\Orbit\Proof\TaskProofReviewFiles;
use App\Tasks\Runtime\TaskAgents;
use App\Tasks\Runtime\TaskRuntimePlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\UsesTaskSharedLocks;

uses(RefreshDatabase::class, UsesTaskSharedLocks::class);

it('revalidates the exact native fifteen-outcome gate for a body-only amendment without rerunning or republishing', function (bool $changed) {
    $landing = dispatchedTaskPackage();
    app(ReviewTaskLanding::class)->submit($landing->id, packageReviewReceipt($landing, 'blocked'));
    $landing->refresh();
    $path = $this->packageDirectory.'/body-correction.txt';
    File::put($path, 'Complete coordinator correction evidence.');
    $request = ['package_hash' => $landing->package_hash, 'review_hash' => TaskLandingData::hash($landing->review_result),
        'reason' => 'Correct the complete PR body.', 'pull_request_body' => $landing->request['pull_request_body']."\nCorrected final-state wording.\n",
        'evidence' => ['path' => $path, 'sha256' => hash_file('sha256', $path)]];
    if ($changed) {
        $this->packageGateData['checks'][0]['exit_code'] = 1;
        File::put($this->packageGate, TaskLandingData::json($this->packageGateData));
        expect(fn () => app(AmendTaskLanding::class)->handle($landing->id, $request, true))
            ->toThrow(OrbitRepositoryFailed::class);
    } else {
        $action = app(AmendTaskLanding::class);
        $preview = $action->handle($landing->id, $request, true);
        $result = $action->handle($landing->id, $request, true, $preview['proposal_hash'], true);
        expect($result['landing']['input_hash'])->toBe($landing->input_hash)
            ->and($result['landing']['artifact_sha'])->toBe($landing->artifact_sha);
    }
    expect($this->packageRepositoryFake->publications)->toBe(1)
        ->and($this->packageReviewerFake->prompts)->toHaveCount(1);
    Queue::assertNothingPushed();
})->with([true, false]);

final class PackageTestRepository implements TaskLandingRepository
{
    public int $publications = 0;

    public bool $lostResponse = false;

    public bool $unpublished = false;

    public bool $changed = false;

    public ?Closure $duringPublish = null;

    public ?string $published = null;

    public ?array $publishedInputs = null;

    public int $level;

    public function __construct(public string $candidate, public string $tree, public string $gate)
    {
        $this->level = DB::transactionLevel();
    }

    public function inspect(TaskWorkspace $workspace, string $candidate, string $gate): array
    {
        expect(DB::transactionLevel())->toBe($this->level);
        if ($this->changed || $candidate !== $this->candidate || $gate !== $this->gate) {
            throw new LogicException('Candidate identity changed.');
        }
        app(OrbitCandidateReceipt::class)->validate($gate, new PreparedWorktree($workspace->worktree, $candidate),
            $this->tree, $workspace->worktree, $workspace->repository.'/.git');
        $contents = TaskLandingData::file($gate);

        return ['candidate' => $candidate, 'tree' => $this->tree, 'gate_path' => $gate,
            'gate_sha256' => hash('sha256', $contents), 'gate_contents' => $contents,
            'flow_contents' => TaskLandingData::json(['schema' => 1, 'flow' => 'discovery'])];
    }

    public function artifact(TaskWorkspace $workspace, string $candidate, array $inputs): ?string
    {
        expect(DB::transactionLevel())->toBe($this->level);
        $expected = ($inputs['schema'] ?? null) === 2 && is_array($inputs['artifact_inputs'] ?? null)
            ? $inputs['artifact_inputs'] : $inputs;
        if ($this->published !== null && $this->publishedInputs !== $expected) {
            throw new LogicException('Existing artifact inputs conflict.');
        }

        return $this->published;
    }

    public function publish(TaskWorkspace $workspace, string $candidate, array $inputs): void
    {
        if (($inputs['schema'] ?? null) === 2 && isset($inputs['artifact_inputs'])) {
            throw new LogicException('Proof artifacts are adopted, not republished.');
        }
        expect(DB::transactionLevel())->toBe($this->level)
            ->and(TaskLanding::query()->where('task_workspace_id', $workspace->id)->sole()->state)->toBe('publishing');
        $this->publications++;
        if ($this->duringPublish !== null) {
            ($this->duringPublish)($workspace);
        }
        if (! $this->unpublished) {
            $this->published = str_repeat('e', 40);
            $this->publishedInputs = $inputs;
        }
        if ($this->lostResponse || $this->unpublished) {
            throw new RuntimeException('Lost native response.');
        }
    }
}

final class PackageTestReviewer implements TaskAgents, TaskLandingReviewer
{
    public bool $yielded = true;

    public bool $changed = false;

    public bool $losePrompt = false;

    public array $prompts = [];

    public ?Closure $duringObserve = null;

    public ?Closure $duringPrompt = null;

    public int $level;

    public function __construct()
    {
        $this->level = DB::transactionLevel();
    }

    public function observe(TaskWorkspace $workspace, array $session, bool $yielded): array
    {
        expect(DB::transactionLevel())->toBe($this->level);
        if ($yielded && ! $this->yielded) {
            throw new LogicException('Reviewer has not yielded.');
        }
        if ($this->duringObserve !== null) {
            ($this->duringObserve)($workspace);
        }

        return HerdrTaskLandingReviewer::identity($this->changed ? [...$session, 'agentId' => 'different-session'] : $session);
    }

    public function assertSession(TaskWorkspace $workspace, array $session): void
    {
        throw new LogicException('Use the supplemental reviewer identity contract.');
    }

    public function start(TaskWorkspace $workspace, string $name): array
    {
        throw new LogicException('No supplemental reviewer may be started.');
    }

    public function prompt(TaskWorkspace $workspace, array $session, string $prompt): array
    {
        expect(DB::transactionLevel())->toBe($this->level);
        $this->prompts[] = $prompt;
        if ($this->duringPrompt !== null) {
            ($this->duringPrompt)($workspace);
        }
        if ($this->losePrompt) {
            throw new RuntimeException('Lost prompt response.');
        }

        return $session;
    }

    public function promptOnce(TaskWorkspace $workspace, array $session, string $prompt): array
    {
        return $this->prompt($workspace, $session, $prompt);
    }
}

final class PackageTestIssues implements OrbitIssueReader
{
    public int $level;

    public ?Closure $duringFetch = null;

    public function __construct(public OrbitIssueSnapshot $snapshot)
    {
        $this->level = DB::transactionLevel();
    }

    public function read(string $issueId, string $issueKey): OrbitIssueSnapshot
    {
        expect(DB::transactionLevel())->toBe($this->level);
        if ($this->duringFetch !== null) {
            ($this->duringFetch)();
        }

        return $this->snapshot;
    }
}

beforeEach(function () {
    Process::preventStrayProcesses();
    Http::preventStrayRequests();
    Queue::fake();
    $this->packageDirectory = storage_path('framework/testing/task-package-'.bin2hex(random_bytes(8)));
    File::makeDirectory($this->packageDirectory.'/projects', 0700, true);
    $this->packageRepository = $this->packageDirectory.'/repository';
    $this->packageWorktree = $this->packageDirectory.'/worktrees/orb-248';
    $this->packageCandidate = str_repeat('b', 40);
    $this->packageTree = str_repeat('c', 40);
    $this->packageGate = $this->packageRepository.'/.git/orbit-checks/'.$this->packageCandidate.'/builder/result.json';
    File::makeDirectory(dirname($this->packageGate), 0700, true);
    File::makeDirectory($this->packageWorktree, 0700, true);
    config(['commander.projects_path' => $this->packageDirectory.'/projects', 'task-runtime.enabled' => true,
        'commander.hermes.orbit_linear_team_id' => '11111111-1111-4111-8111-111111111111']);
    app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
    $this->packageRoot = app(CreateTask::class)->handle('orbit', 'Feature package', 'Deliver the scoped feature.',
        TaskKind::Group, acceptanceCriteria: 'The feature works with retained proof.');
    $this->packageTask = app(CreateTask::class)->handle('orbit', 'Implement feature', 'One focused implementation.',
        parent: $this->packageRoot, acceptanceCriteria: 'All focused checks pass.');
    $this->packageRun = TaskRun::query()->create(['task_id' => $this->packageTask->id, 'root_task_id' => $this->packageRoot->id,
        'attempt' => 1, 'idempotency_key' => 'accepted-test', 'worker_ref' => 'implementer', 'reviewer_ref' => 'reviewer',
        'base_sha' => str_repeat('a', 40), 'commit_sha' => $this->packageCandidate, 'status' => TaskRunStatus::Completed,
        'input' => ['private_unrelated_input' => 'not-exported'], 'output' => ['summary' => 'Accepted work.', 'evidence' => 'Focused checks passed.'],
        'started_at' => now(), 'finished_at' => now()]);
    $this->packageRun->reviews()->create(['round' => 1, 'tree_sha' => $this->packageTree,
        'output' => ['summary' => 'Ready.', 'evidence' => 'Tests passed.'], 'requested_at' => now(),
        'verdict' => 'pass', 'summary' => 'Reviewed exact work.', 'evidence_ref' => 'Focused evidence verified.', 'reviewed_at' => now()]);
    $this->packageTask->update(['status' => TaskStatus::Completed, 'completed_at' => now(), 'accepted_task_run_id' => $this->packageRun->id]);
    $this->packageRoot->update(['status' => TaskStatus::Completed, 'completed_at' => now()]);
    $this->packageSession = ['workspaceId' => 'w1', 'tabId' => 't1', 'paneId' => 'p1', 'terminalId' => 'term1',
        'agentName' => 'reviewer', 'agentId' => '77777777-7777-4777-8777-777777777777', 'workingDirectory' => $this->packageWorktree];
    $final = ['verdict' => 'pass', 'summary' => 'Integrated feature passed.', 'evidence' => 'All root criteria and Incus proof verified.'];
    $this->packageWorkspace = TaskWorkspace::query()->create(['root_task_id' => $this->packageRoot->id,
        'project_id' => 'orbit', 'source_key' => 'ORB-248', 'repository' => $this->packageRepository, 'worktree' => $this->packageWorktree,
        'base_sha' => str_repeat('a', 40), 'manifest_hash' => app(TaskRuntimePlan::class)->hash($this->packageRoot),
        'configuration' => ['repository' => $this->packageRepository, 'worktree_root' => dirname($this->packageWorktree), 'flow_version' => 1,
            'socket' => '/unused.sock', 'agent_kind' => 'codex', 'instructions' => 'private-runtime-instructions'],
        'herdr_workspace' => ['workspaceId' => 'w1', 'paneId' => 'p1'], 'reviewer_session' => $this->packageSession,
        'final_check' => ['sha' => $this->packageCandidate, 'exit_code' => 0, 'output' => 'legacy final evidence'], 'final_result' => $final]);
    $this->packageFinal = $this->packageWorkspace->dispatches()->create(['step_key' => 'feature:final', 'kind' => 'final_review',
        'state' => 'acknowledged', 'token_hash' => hash('sha256', 'prior-private-token'), 'handoff_token' => 'prior-private-token',
        'prompt' => 'Raw private original final prompt.', 'session' => $this->packageSession, 'result' => $final]);
    $checks = [];
    foreach (['apps/cli', 'apps/docs', 'apps/gateway', 'apps/e2e', 'packages/php-sdk'] as $project) {
        foreach ([['composer', 'validate', '--strict'], ['composer', 'check'], ['composer', 'test:affected']] as $command) {
            $checks[] = ['project' => $project, 'command' => $command, 'exit_code' => 0];
        }
    }
    $this->packageGateData = ['schema' => 1, 'role' => 'builder', 'candidate' => $this->packageCandidate, 'tree' => $this->packageTree,
        'worktree' => $this->packageWorktree, 'passed' => true, 'unchanged' => true, 'checks' => $checks];
    File::put($this->packageGate, TaskLandingData::json($this->packageGateData));
    $this->packageRequest = ['candidate' => $this->packageCandidate, 'manifest' => $this->packageWorkspace->manifest_hash,
        'final_dispatch' => $this->packageFinal->id, 'issue_id' => '22222222-2222-4222-8222-222222222222', 'gate_receipt' => $this->packageGate,
        'pull_request_body' => "## Acceptance evidence\n\nFeature worked in the focused check; retained proof: accepted task handoff.\n\n## Checks\n\nFocused check and all 15 Builder outcomes exited 0.\n\n## Documentation\n\nNo public behavior change; no docs update required.\n\n## Deviations and limits\n\nNone observed in this disposable fixture.\n\n## Resources\n\nDisposable Incus proof is complete; resource closeout is not authorized by this package.\n"];
    $this->packageRepositoryFake = new PackageTestRepository($this->packageCandidate, $this->packageTree, $this->packageGate);
    $this->packageReviewerFake = new PackageTestReviewer;
    $this->packageIssueFake = new PackageTestIssues(new OrbitIssueSnapshot($this->packageRequest['issue_id'], 'ORB-248',
        ['id' => $this->packageRequest['issue_id'], 'identifier' => 'ORB-248', 'title' => 'Feature package',
            'description' => 'Deliver the scoped feature. The feature works with retained proof.',
            'state' => ['name' => 'In Review', 'type' => 'started'], 'delegate' => null, 'assignee' => null,
            'team' => ['id' => config('commander.hermes.orbit_linear_team_id')],
            'children' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]],
            'inverseRelations' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]],
            'labels' => ['nodes' => [['name' => 'incus'], ['name' => 'docs']], 'pageInfo' => ['hasNextPage' => false]],
            'attachments' => ['nodes' => [['title' => 'Retained proof', 'url' => 'https://example.test/private?token=not-exported'],
                ['title' => 'Relevant repository issue', 'url' => 'https://github.com/nckrtl/orbit/issues/12']], 'pageInfo' => ['hasNextPage' => false]]], str_repeat('d', 64)));
    app()->instance(TaskLandingRepository::class, $this->packageRepositoryFake);
    app()->instance(TaskLandingReviewer::class, $this->packageReviewerFake);
    app()->instance(TaskAgents::class, $this->packageReviewerFake);
    app()->instance(OrbitIssueReader::class, $this->packageIssueFake);
});

afterEach(fn () => File::deleteDirectory($this->packageDirectory));

function preparedTaskPackage(): TaskLanding
{
    $test = test();
    $prepare = app(PrepareTaskLanding::class);
    $preview = $prepare->handle($test->packageWorkspace->id, $test->packageRequest, true);
    $result = $prepare->handle($test->packageWorkspace->id, $test->packageRequest, true, $preview['proposal_hash'], true);

    return TaskLanding::query()->findOrFail($result['landing']['id']);
}

function dispatchedTaskPackage(): TaskLanding
{
    $landing = preparedTaskPackage();
    app(ReviewTaskLanding::class)->dispatch($landing->id, $landing->package_hash, true, true);

    return $landing->refresh();
}

function preparedProofTaskPackage(int $manifestEntries = 0): TaskLanding
{
    $test = test();
    app()->useStoragePath($test->packageDirectory.'/storage');
    $artifact = str_repeat('e', 40);
    $attempt = str_repeat('1', 32);
    $fingerprint = str_repeat('2', 64);
    $artifactInputs = ['schema' => 2, 'authority' => 'pre-proof',
        'repository' => ['candidate' => $test->packageCandidate, 'tree' => $test->packageTree,
            'flow_contents' => TaskLandingData::json(['schema' => 1, 'flow' => 'proof'])],
        'proof_contract' => [['path' => '.loop/proof/ORB-248.json', 'mode' => '100644',
            'sha256' => str_repeat('3', 64), 'contents' => '{"snapshot_replacement":true}']]];
    $topology = ['construction' => ['snapshot_replacement' => true], 'attempt_id' => $attempt, 'purpose' => 'proof'];
    $capture = ['attempt_id' => $attempt, 'candidate_sha' => $test->packageCandidate, 'fingerprint' => $fingerprint];
    for ($index = 0; $index < $manifestEntries; $index++) {
        $capture['manifest']['inputs'][] = ['path' => 'apps/e2e/app/E2E/Fixture'.$index.'.php',
            'classification' => 'runtime', 'mode' => '100644', 'blob' => hash('sha1', (string) $index)];
    }
    $review = ['schema' => 1, 'status' => ['capture' => $capture], 'capture' => $capture,
        'review_record' => ['actions' => [['id' => 'browser', 'status' => 'passed']]],
        'review_evaluation' => ['status' => 'ready'], 'retained_topology' => $topology,
        'archives' => ['capture' => ['sha256' => TaskLandingData::hash($capture), 'contents' => TaskLandingData::json($capture)]]];
    $prepared = ['schema' => 1, 'attempt_id' => $attempt, 'capture_fingerprint' => $fingerprint,
        'retained_topology' => $topology,
        'artifact' => ['ref' => 'refs/tags/loop/orb-248/'.$test->packageCandidate, 'sha' => $artifact,
            'input_sha256' => TaskLandingData::hash($artifactInputs),
            'commander_tasks_sha256' => hash('sha256', TaskLandingData::json($artifactInputs)),
            'plan_path' => '.loop/proof/ORB-248.json', 'plan_sha256' => str_repeat('3', 64)],
        'artifact_inputs' => $artifactInputs, 'prove' => ['attempt_id' => $attempt], 'capture' => $capture];
    $configuration = [...$test->packageWorkspace->configuration,
        'orbit_profile' => ['schema' => 1, 'flow' => 'proof', 'snapshot_replacement' => true]];
    $check = ['sha' => $test->packageCandidate, 'manifest_hash' => $test->packageWorkspace->manifest_hash,
        'candidate_unchanged' => true, 'command' => ['composer', 'check'], 'exit_code' => 0,
        'output' => 'passed', 'error_output' => '', 'native_proof' => $prepared];
    DB::table('task_agent_dispatches')->where('id', $test->packageFinal->id)->update([
        'final_check_version' => 1, 'final_check' => json_encode($check, JSON_THROW_ON_ERROR),
    ]);
    DB::table('task_workspaces')->where('id', $test->packageWorkspace->id)->update([
        'configuration' => json_encode($configuration, JSON_THROW_ON_ERROR),
        'final_check' => json_encode($check, JSON_THROW_ON_ERROR),
        'final_result' => json_encode([...$test->packageFinal->result, 'native_proof_review' => $review], JSON_THROW_ON_ERROR),
    ]);
    $test->packageWorkspace->refresh();
    $test->packageFinal->refresh();
    $test->packageRepositoryFake->published = $artifact;
    $test->packageRepositoryFake->publishedInputs = $artifactInputs;

    return preparedTaskPackage();
}

it('adopts the unchanged pre-proof artifact and binds later native evidence in a schema-2 landing package', function () {
    $landing = preparedProofTaskPackage();
    $artifact = str_repeat('e', 40);
    $artifactInputs = $this->packageRepositoryFake->publishedInputs;
    $review = $this->packageWorkspace->final_result['native_proof_review'];

    expect($landing->inputs['schema'])->toBe(2)
        ->and($landing->artifact_sha)->toBe($artifact)
        ->and($landing->inputs['artifact_inputs'])->toBe($artifactInputs)
        ->and($landing->inputs['native_proof']['final_review'])->toBe($review)
        ->and($landing->package['schema'])->toBe(2)
        ->and($landing->package['native_proof'])->toBe($landing->inputs['native_proof'])
        ->and($landing->package['proof_evidence_hash'])->toBe(TaskLandingData::hash($landing->inputs['native_proof']))
        ->and($this->packageRepositoryFake->publications)->toBe(0);
    app(ReviewTaskLanding::class)->dispatch($landing->id, $landing->package_hash, true, true);
    expect($this->packageReviewerFake->prompts)->toHaveCount(1)
        ->and($this->packageReviewerFake->prompts[0])->toContain('schema-2 proof package',
            'artifact intentionally predates these records', 'Do not run native prove, capture',
            'Frozen landing evidence outside the pre-proof artifact',
            '"raw_sha256": "'.TaskLandingData::hash($artifactInputs).'"')
        ->and($this->packageReviewerFake->prompts[0])->not->toContain('"raw_sha256": "'.$landing->input_hash.'"');
    $this->packageRepositoryFake->changed = true;
    app(TaskLandingEvidence::class)->guard($this->packageWorkspace, $landing->request, $landing->inputs);
    $changedResult = $this->packageWorkspace->final_result;
    $changedResult['native_proof_review']['review_evaluation']['status'] = 'blocked';
    DB::table('task_workspaces')->where('id', $this->packageWorkspace->id)->update([
        'final_result' => json_encode($changedResult, JSON_THROW_ON_ERROR),
    ]);
    expect(fn () => app(TaskLandingEvidence::class)->guard($this->packageWorkspace, $landing->request, $landing->inputs))
        ->toThrow(LogicException::class, 'final evidence changed');
});

it('dispatches 125 native manifest entries through the real transport using exact private evidence files', function () {
    $landing = preparedProofTaskPackage(125);
    $files = app(TaskProofReviewFiles::class);
    $values = [$landing->package, array_diff_key($landing->inputs, ['artifact_inputs' => true, 'native_proof' => true])];
    $before = [$landing->inputs, $landing->input_hash, $landing->package, $landing->package_hash,
        $this->packageWorkspace->getRawOriginal(), $this->packageFinal->getRawOriginal(), $this->packageRepositoryFake->publishedInputs];
    $preview = app(ReviewTaskLanding::class)->dispatch($landing->id, $landing->package_hash, true);
    expect($preview['transport']['wire_bytes'])->toBeLessThan(TaskLandingReviewTransport::MAX_REQUEST_BYTES)
        ->and($landing->refresh()->review_assignment)->toBeNull()
        ->and($this->packageReviewerFake->prompts)->toBeEmpty();
    foreach ($values as $value) {
        expect(file_exists($files->reference($landing->task_workspace_id, $value)['path']))->toBeFalse();
    }
    app(ReviewTaskLanding::class)->dispatch($landing->id, $landing->package_hash, true, true);
    $landing->refresh();
    $prompt = $landing->review_prompt;
    expect(TaskLandingReviewTransport::inspect($landing->review_session, $prompt)['wire_bytes'])
        ->toBeLessThan(TaskLandingReviewTransport::MAX_REQUEST_BYTES)
        ->and(strlen(TaskLandingData::json($landing->package)))->toBeGreaterThan(TaskLandingReviewTransport::MAX_REQUEST_BYTES)
        ->and($prompt)->not->toContain('Fixture124.php', '"archives":', '"final_review":', '"accepted_tasks":');
    foreach ($values as $value) {
        $reference = $files->reference($landing->task_workspace_id, $value);
        expect($prompt)->toContain(TaskLandingData::json($reference))
            ->and(TaskLandingData::file($reference['path'], 8_388_608))->toBe(TaskLandingData::json($value))
            ->and(hash_file('sha256', $reference['path']))->toBe($reference['raw_sha256'])
            ->and(filesize($reference['path']))->toBe($reference['raw_bytes'])
            ->and(fileperms($reference['path']) & 0777)->toBe(0600)
            ->and(fileperms(dirname($reference['path'])) & 0777)->toBe(0700);
    }
    expect([$landing->inputs, $landing->input_hash, $landing->package, $landing->package_hash,
        $this->packageWorkspace->fresh()->getRawOriginal(), $this->packageFinal->fresh()->getRawOriginal(), $this->packageRepositoryFake->publishedInputs])->toBe($before)
        ->and($this->packageRepositoryFake->publications)->toBe(0);
    app(ReviewTaskLanding::class)->submit($landing->id, packageReviewReceipt($landing));
    expect($landing->refresh()->state)->toBe('approved');
});

it('rejects damaged private proof references before accepting the supplemental review', function (string $damage) {
    $landing = preparedProofTaskPackage();
    app(ReviewTaskLanding::class)->dispatch($landing->id, $landing->package_hash, true, true);
    $landing->refresh();
    $files = app(TaskProofReviewFiles::class);
    $reference = $files->reference($landing->task_workspace_id, $landing->package);
    if ($damage === 'missing') {
        File::delete($reference['path']);
    } elseif ($damage === 'bytes') {
        File::put($reference['path'], '{}');
    } elseif ($damage === 'mode') {
        chmod($reference['path'], 0644);
    } else {
        File::move($reference['path'], $reference['path'].'.original');
        symlink($reference['path'].'.original', $reference['path']);
    }
    expect(fn () => app(ReviewTaskLanding::class)->submit($landing->id, packageReviewReceipt($landing)))
        ->toThrow($damage === 'missing' || $damage === 'symlink' ? InvalidArgumentException::class : LogicException::class)
        ->and($landing->refresh()->review_result)->toBeNull();
    if ($damage !== 'missing') {
        expect(fn () => $files->retain($this->packageWorkspace, $landing->package))
            ->toThrow($damage === 'symlink' ? InvalidArgumentException::class : LogicException::class);
    }
})->with(['missing', 'bytes', 'mode', 'symlink']);

it('never exports dispatch secrets in private proof review files', function (string $secret) {
    $files = app(TaskProofReviewFiles::class);
    $value = ['native_proof' => $secret === 'token' ? $this->packageFinal->handoff_token : $this->packageFinal->prompt];
    $reference = $files->reference($this->packageWorkspace->id, $value);
    expect(fn () => $files->retain($this->packageWorkspace, $value))->toThrow(LogicException::class, 'private dispatch')
        ->and(file_exists($reference['path']))->toBeFalse();
})->with(['token', 'prompt']);

it('rejects redirected or public proof directories before claiming or exporting a review', function (string $damage) {
    $landing = preparedProofTaskPackage();
    $reference = app(TaskProofReviewFiles::class)->reference($landing->task_workspace_id, $landing->package);
    if ($damage === 'public') {
        File::makeDirectory(dirname($reference['path']), 0755, true);
    } else {
        File::makeDirectory(storage_path('app/private'), 0700, true);
        symlink($this->packageWorktree, storage_path('app/private/task-proof-reviews'));
    }
    expect(fn () => app(ReviewTaskLanding::class)->dispatch($landing->id, $landing->package_hash, true, true))
        ->toThrow(LogicException::class, 'private proof review evidence directory')
        ->and($landing->refresh()->review_assignment)->toBeNull()
        ->and($this->packageReviewerFake->prompts)->toBeEmpty()
        ->and(file_exists($reference['path']))->toBeFalse()
        ->and(is_dir($this->packageWorktree.'/'.$landing->task_workspace_id))->toBeFalse();
})->with(['public', 'symlink']);

it('keeps exact schema-1 final receipt matching when a proof-only field is present', function () {
    DB::table('task_workspaces')->where('id', $this->packageWorkspace->id)->update([
        'final_result' => json_encode([...$this->packageFinal->result, 'native_proof_review' => ['unexpected' => true]], JSON_THROW_ON_ERROR),
    ]);
    $this->packageWorkspace->refresh();

    expect(fn () => preparedTaskPackage())->toThrow(LogicException::class, 'exact successful final review');
});

function packageReviewReceipt(TaskLanding $landing, string $verdict = 'pass'): array
{
    return ['assignment' => $landing->review_assignment, 'token' => $landing->review_token,
        'package_hash' => $landing->package_hash, 'candidate_sha' => $landing->candidate_sha, 'artifact_sha' => $landing->artifact_sha,
        'session' => $landing->review_session, 'verdict' => $verdict, 'summary' => 'Exact package reviewed.',
        'evidence' => 'Candidate, artifact, 15 Builder outcomes, retained proof and full PR body verified.'];
}

it('previews without writes and freezes a separate complete package without changing accepted task history', function () {
    $before = [$this->packageRoot->fresh()->toArray(), $this->packageWorkspace->fresh()->toArray(), $this->packageFinal->fresh()->toArray(), $this->packageRun->fresh()->toArray()];
    $preview = app(PrepareTaskLanding::class)->handle($this->packageWorkspace->id, $this->packageRequest, true);
    expect(TaskLanding::query()->count())->toBe(0)->and($this->packageRepositoryFake->publications)->toBe(0)
        ->and($preview['inputs']['database']['accepted_tasks'][0]['commit_sha'])->toBe($this->packageCandidate)
        ->and(TaskLandingData::json($preview))->not->toContain('prior-private-token', 'Raw private original', 'private-runtime-instructions', 'private_unrelated_input');
    $landing = preparedTaskPackage();
    expect($landing->state)->toBe('packaged')->and($landing->review_result)->toBeNull()
        ->and($landing->package_hash)->toBe(TaskLandingData::hash($landing->package))
        ->and($landing->package['body'])->toContain('Issue: ORB-248', $landing->artifact_sha, $landing->candidate_sha, 'Flow: discovery', 'Builder gate: passed', $landing->input_hash)
        ->and($landing->package['body'])->toContain($this->packageRequest['pull_request_body'])
        ->and($landing->request['pull_request_body'])->toBe($this->packageRequest['pull_request_body'])
        ->and([$this->packageRoot->fresh()->toArray(), $this->packageWorkspace->fresh()->toArray(), $this->packageFinal->fresh()->toArray(), $this->packageRun->fresh()->toArray()])->toBe($before)
        ->and(TaskAgentDispatch::query()->count())->toBe(1)->and($this->packageReviewerFake->prompts)->toBe([]);
    Queue::assertNothingPushed();
});

it('requires explicit ownership opt-in and the exact preview hash for apply', function () {
    $prepare = app(PrepareTaskLanding::class);
    expect(fn () => $prepare->handle($this->packageWorkspace->id, $this->packageRequest, false))->toThrow(LogicException::class)
        ->and(fn () => $prepare->handle($this->packageWorkspace->id, $this->packageRequest, true, str_repeat('0', 64), true))->toThrow(LogicException::class, 'Preview and pin');
    config(['task-runtime.enabled' => false]);
    expect(fn () => $prepare->handle($this->packageWorkspace->id, $this->packageRequest, true, null, true))->toThrow(LogicException::class)
        ->and(TaskLanding::query()->count())->toBe(0)->and($this->packageRepositoryFake->publications)->toBe(0);
});

it('previews an undelegated Tasks package through the actual SSH issue reader and factory', function () {
    $payload = $this->packageIssueFake->snapshot->payload;
    $payload['url'] = 'https://linear.app/orbit/issue/ORB-248';
    $payload['updatedAt'] = '2026-09-12T22:00:00Z';
    $payload['state']['id'] = '33333333-3333-4333-8333-333333333333';
    $payload['team']['states'] = ['nodes' => [['id' => $payload['state']['id'], 'name' => 'In Review']]];
    config(['commander.hermes.ssh_target' => 'tom@mini', 'commander.hermes.profiles.tom' => '/Users/tom/.hermes/profiles/tom',
        'commander.hermes.tom_linear_viewer_id' => '4fa61558-9052-45f7-8a7c-49e0b891d4bf']);
    Process::fake(['*' => Process::result(output: json_encode(['data' => [
        'viewer' => ['id' => config('commander.hermes.tom_linear_viewer_id')], 'issue' => $payload,
    ]], JSON_THROW_ON_ERROR))])->preventStrayProcesses();
    app()->instance(OrbitIssueReader::class, app(SshOrbitIssueProvider::class));
    $preview = app(PrepareTaskLanding::class)->handle($this->packageWorkspace->id, $this->packageRequest, true);
    expect($preview['inputs']['issue']['identifier'])->toBe('ORB-248')
        ->and($preview['inputs']['issue']['labels'])->toBe(['docs', 'incus'])
        ->and(TaskLanding::query()->count())->toBe(0)->and($this->packageRepositoryFake->publications)->toBe(0)
        ->and($this->packageRoot->fresh()->status)->toBe(TaskStatus::Completed);
    Process::assertRanTimes(fn (): bool => true, 1);
    Queue::assertNothingPushed();
});

it('binds proposed PR wording separately from immutable candidate artifact inputs', function () {
    $prepare = app(PrepareTaskLanding::class);
    $first = $prepare->handle($this->packageWorkspace->id, $this->packageRequest, true);
    $changed = [...$this->packageRequest, 'pull_request_body' => $this->packageRequest['pull_request_body']."\nA precise wording correction.\n"];
    $second = $prepare->handle($this->packageWorkspace->id, $changed, true);
    expect($first['input_hash'])->toBe($second['input_hash'])->and($first['inputs'])->toBe($second['inputs'])
        ->and($first['proposal_hash'])->not->toBe($second['proposal_hash'])
        ->and(TaskLandingData::json($first['inputs']))->not->toContain('## Acceptance evidence');
    expect(fn () => $prepare->handle($this->packageWorkspace->id, $changed, true, $first['proposal_hash'], true))
        ->toThrow(LogicException::class, 'proposal hash')->and(TaskLanding::query()->count())->toBe(0);
});

it('exports actual current Linear requirements without signed attachments and rejects contract drift', function (string $change) {
    $landing = preparedTaskPackage();
    expect($landing->inputs['issue']['title'])->toBe('Feature package')
        ->and($landing->inputs['issue']['description'])->toBe('Deliver the scoped feature. The feature works with retained proof.')
        ->and($landing->inputs['issue']['labels'])->toBe(['docs', 'incus'])
        ->and($landing->inputs['issue']['attachments'])->toBe([
            ['title' => 'Retained proof', 'repository_url' => null],
            ['title' => 'Relevant repository issue', 'repository_url' => 'https://github.com/nckrtl/orbit/issues/12'],
        ])
        ->and(TaskLandingData::json($landing->inputs))->not->toContain('https://example.test/private', 'token=not-exported');
    $snapshot = $this->packageIssueFake->snapshot;
    $changed = $change === 'description'
        ? ['description' => 'A changed requirement needing coordinator reconciliation.']
        : ['labels' => ['nodes' => [['name' => 'docs'], ['name' => 'new-product-classification']], 'pageInfo' => ['hasNextPage' => false]]];
    $this->packageIssueFake->snapshot = new OrbitIssueSnapshot($snapshot->issueId, $snapshot->issueKey,
        [...$snapshot->payload, ...$changed], $snapshot->contractHash);
    expect(fn () => app(ReviewTaskLanding::class)->dispatch($landing->id, $landing->package_hash, true, true))->toThrow(LogicException::class, 'changed after')
        ->and($this->packageReviewerFake->prompts)->toBe([]);
})->with(['description', 'classification']);

it('requires a proposed PR body before publishing package evidence', function (?string $body) {
    $request = [...$this->packageRequest, 'pull_request_body' => $body];
    expect(fn () => app(PrepareTaskLanding::class)->handle($this->packageWorkspace->id, $request, true))
        ->toThrow(InvalidArgumentException::class, 'pull_request_body')
        ->and(TaskLanding::query()->count())->toBe(0)->and($this->packageRepositoryFake->publications)->toBe(0);
})->with([null, '', " \n"]);

it('refuses a conflicting active Delivery by source or worktree', function (bool $sameSource) {
    $project = DB::table('project_orchestrations')->insertGetId(['manifest_project_id' => 'orbit', 'config' => '{}']);
    DB::table('deliveries')->insert(['project_orchestration_id' => $project, 'external_issue_provider' => 'linear',
        'external_issue_id' => 'another-issue', 'external_issue_key' => $sameSource ? 'ORB-248' : 'ORB-999',
        'workflow_type' => 'orbit', 'workflow_version' => 1, 'current_phase' => 'implementation',
        'active_issue_key' => str_repeat('a', 64), 'worktree_path' => $sameSource ? '/another/worktree' : $this->packageWorktree]);
    expect(fn () => preparedTaskPackage())->toThrow(LogicException::class, 'Another controller')
        ->and(TaskLanding::query()->count())->toBe(0)->and($this->packageRepositoryFake->publications)->toBe(0);
})->with([true, false]);

it('rejects malformed or insufficient actual Builder receipts before publishing', function (string $defect) {
    $gate = $this->packageGateData;
    match ($defect) {
        'missing outcome' => array_pop($gate['checks']),
        'duplicate outcome' => $gate['checks'][14] = $gate['checks'][0],
        'failed outcome' => $gate['checks'][0]['exit_code'] = 1,
        'wrong candidate' => $gate['candidate'] = str_repeat('f', 40),
        'wrong tree' => $gate['tree'] = str_repeat('f', 40),
        'wrong worktree' => $gate['worktree'] = $this->packageDirectory,
        'not builder' => $gate['role'] = 'reviewer',
        'not passed' => $gate['passed'] = false,
        'changed candidate' => $gate['unchanged'] = false,
    };
    File::put($this->packageGate, TaskLandingData::json($gate));
    expect(fn () => preparedTaskPackage())->toThrow(RuntimeException::class)
        ->and(TaskLanding::query()->count())->toBe(0)->and($this->packageRepositoryFake->publications)->toBe(0);
})->with(['missing outcome', 'duplicate outcome', 'failed outcome', 'wrong candidate', 'wrong tree', 'wrong worktree', 'not builder', 'not passed', 'changed candidate']);

it('rejects changed completion, final assignment, accepted candidate or manifest', function (string $defect) {
    match ($defect) {
        'pending root' => DB::table('tasks')->where('id', $this->packageRoot->id)->update(['status' => 'pending']),
        'older final' => $this->packageRequest['final_dispatch'] = 999,
        'wrong candidate' => $this->packageRequest['candidate'] = str_repeat('f', 40),
        'changed manifest' => $this->packageRequest['manifest'] = str_repeat('f', 64),
        'changed checkout' => $this->packageRepositoryFake->changed = true,
        'owner hold' => $this->packageWorkspace->update(['attention' => 'Another action owns this workspace.']),
        'different reviewer' => $this->packageWorkspace->update(['reviewer_session' => [...$this->packageSession, 'agentId' => 'other']]),
    };
    expect(fn () => preparedTaskPackage())->toThrow(LogicException::class)
        ->and(TaskLanding::query()->count())->toBe(0);
})->with(['pending root', 'older final', 'wrong candidate', 'changed manifest', 'changed checkout', 'owner hold', 'different reviewer']);

it('rejects mismatched Linear identity or team without recording an intent', function (string $field) {
    $snapshot = $this->packageIssueFake->snapshot;
    $payload = $snapshot->payload;
    if ($field === 'team') {
        $payload['team']['id'] = 'other';
    } else {
        $payload[$field] = 'other';
    }
    $this->packageIssueFake->snapshot = new OrbitIssueSnapshot($snapshot->issueId, $snapshot->issueKey, $payload, $snapshot->contractHash);
    expect(fn () => preparedTaskPackage())->toThrow(LogicException::class, 'Linear issue')
        ->and(TaskLanding::query()->count())->toBe(0);
})->with(['id', 'identifier', 'team']);

it('reconciles a lost artifact response and never repeats a confirmed publication', function () {
    $this->packageRepositoryFake->lostResponse = true;
    $landing = preparedTaskPackage();
    $again = app(PrepareTaskLanding::class)->handle($this->packageWorkspace->id, $this->packageRequest, true,
        TaskLandingData::hash(['input_hash' => $landing->input_hash, 'request' => $landing->request]), true);
    expect($landing->state)->toBe('packaged')->and($again['applied'])->toBeFalse()
        ->and($this->packageRepositoryFake->publications)->toBe(1)->and(TaskLanding::query()->count())->toBe(1);
});

it('keeps unresolved publication intent and allows only read-back reconciliation', function () {
    $this->packageRepositoryFake->unpublished = true;
    expect(fn () => preparedTaskPackage())->toThrow(LogicException::class, 'remains unresolved');
    $landing = TaskLanding::query()->sole();
    expect($landing->state)->toBe('publication_unknown');
    $proposal = TaskLandingData::hash(['input_hash' => $landing->input_hash, 'request' => $landing->request]);
    expect(fn () => app(PrepareTaskLanding::class)->handle($this->packageWorkspace->id, $this->packageRequest, true, $proposal, true))
        ->toThrow(LogicException::class, 'remains unresolved');
    expect($this->packageRepositoryFake->publications)->toBe(1);
    $this->packageRepositoryFake->published = str_repeat('e', 40);
    $this->packageRepositoryFake->publishedInputs = $landing->inputs;
    app(PrepareTaskLanding::class)->handle($this->packageWorkspace->id, $this->packageRequest, true, $proposal, true);
    expect($landing->refresh()->state)->toBe('packaged')->and($this->packageRepositoryFake->publications)->toBe(1);
});

it('refuses an already published mismatched artifact without an overwrite', function () {
    $this->packageRepositoryFake->published = str_repeat('e', 40);
    $this->packageRepositoryFake->publishedInputs = ['other' => true];
    expect(fn () => preparedTaskPackage())->toThrow(LogicException::class, 'artifact inputs conflict')
        ->and($this->packageRepositoryFake->publications)->toBe(0)->and(TaskLanding::query()->count())->toBe(0);
});

it('revalidates ledger ownership after external observation and after publication', function (string $when) {
    $change = fn () => $this->packageWorkspace->update(['attention' => 'Ownership changed.']);
    if ($when === 'inspection') {
        $this->packageIssueFake->duringFetch = $change;
    } else {
        $this->packageRepositoryFake->duringPublish = $change;
    }
    expect(fn () => preparedTaskPackage())->toThrow(LogicException::class);
    expect(TaskLanding::query()->first()?->state)->toBe($when === 'inspection' ? null : 'publication_unknown');
})->with(['inspection', 'publication']);

it('embeds explicit retained evidence by hash and refuses private tokens or raw prompts', function (string $contents) {
    $file = $this->packageDirectory.'/proof.log';
    File::put($file, $contents);
    $this->packageRequest['evidence_files'] = [['name' => 'proof.log', 'path' => $file, 'sha256' => hash('sha256', $contents)]];
    if ($contents !== 'Disposable Incus proof: all checks passed.') {
        expect(fn () => preparedTaskPackage())->toThrow(LogicException::class, 'private dispatch');
        expect($this->packageRepositoryFake->publications)->toBe(0);

        return;
    }
    $landing = preparedTaskPackage();
    expect($landing->inputs['evidence_files'][0]['contents'])->toBe($contents)
        ->and($landing->inputs['retention'])->toContain('not archived proof');
    File::put($file, 'Changed proof.');
    expect(fn () => app(ReviewTaskLanding::class)->dispatch($landing->id, $landing->package_hash, true))->toThrow(LogicException::class, 'evidence file changed');
})->with(['Disposable Incus proof: all checks passed.', 'prior-private-token', 'Raw private original final prompt.']);

it('references complete multi-megabyte evidence without changing its frozen bytes or full PR body', function () {
    $this->packageRequest['pull_request_body'] .= "\nFull body tail: Unicode ✓ and quoted \"acceptance\" retained.\n";
    foreach (['failed-first', 'failed-second', 'passed-last'] as $name) {
        $contents = str_repeat($name." evidence \"entry\"\n", 30_000);
        $path = $this->packageDirectory.'/'.$name.'.log';
        File::put($path, $contents);
        $this->packageRequest['evidence_files'][] = ['name' => $name.'.log', 'path' => $path, 'sha256' => hash('sha256', $contents)];
    }
    $landing = preparedTaskPackage();
    $before = [$landing->inputs, $landing->input_hash, $landing->package, $landing->package_hash, $landing->artifact_sha];
    app(ReviewTaskLanding::class)->dispatch($landing->id, $landing->package_hash, true, true);
    $landing->refresh();
    $prompt = $landing->review_prompt;
    expect(strlen(TaskLandingData::json($landing->inputs)))->toBeGreaterThan(2_500_000)
        ->and($prompt)->toContain(TaskLandingData::json($landing->package), $landing->artifact_ref, $landing->input_hash,
            (string) strlen(TaskLandingData::json($landing->inputs)), 'every input section', 'every embedded evidence file',
            'failed and successful check history', '.loop/commander-tasks.json', $landing->review_assignment, $landing->review_token)
        ->and($prompt)->toContain(
            'Commander has already verified that the exact ref resolves to this artifact SHA',
            'git --no-replace-objects show '.$landing->artifact_sha.':.loop/commander-tasks.json',
            'Use those as binding guarantees, not as correctness or proof-adequacy judgments',
            'reuse only your own recorded independent assessment when the export names the same reviewer, task/run, and source tree',
            'Cite the prior review ID/tree or final dispatch once',
            'do not inspect Commander source or its database solely to reconstruct that binding',
            'do not manually hash, byte-count, copy, or restate unchanged handoffs, reviews, or final evidence',
            "Another agent's summary or approval is not your assessment",
            'Inspect all new, changed, unresolved or inadequately recorded content completely in bounded reads',
            'If your own prior assessment is unavailable or its native binding does not match, perform the full relevant independent review',
            'Submit a fresh verdict bound to this exact package and assignment; prior approval never transfers',
            'Always inspect the complete current PR title/body', 'actual successful Builder receipt and final-check record',
            'final-check record belong to the exact unchanged candidate', 'judge root acceptance, integration and cross-task interactions',
            'complete explicit current Linear title, description, label classifications and attachment titles/safe repository links',
            'even when reusing an assessment', 'requirements/classifications must return blocked for coordinator reconciliation')
        ->and($prompt)->not->toContain('failed-first evidence', 'passed-last evidence', 'full-byte evidence-hash bindings',
            'verify its full byte count and SHA256')
        ->and(TaskLandingReviewTransport::inspect($landing->review_session, $prompt)['wire_bytes'])->toBeLessThan(65_536)
        ->and([$landing->inputs, $landing->input_hash, $landing->package, $landing->package_hash, $landing->artifact_sha])->toBe($before)
        ->and($this->packageRepositoryFake->publications)->toBe(1);
    foreach ($landing->inputs['evidence_files'] as $file) {
        expect(hash('sha256', $file['contents']))->toBe($file['sha256']);
    }
});

it('rejects JSON expansion before claiming any ordinary supplemental assignment', function () {
    $this->packageRequest['pull_request_body'] .= str_repeat("\t", 35_000);
    $landing = preparedTaskPackage();
    $before = $landing->getRawOriginal();
    expect(fn () => app(ReviewTaskLanding::class)->dispatch($landing->id, $landing->package_hash, true, true))
        ->toThrow(LogicException::class, 'serialized supplemental review request')
        ->and($landing->refresh()->getRawOriginal())->toBe($before)
        ->and($this->packageReviewerFake->prompts)->toBe([]);
});

it('freezes input and package fields and keeps supplemental secrets encrypted and hidden', function () {
    $landing = dispatchedTaskPackage();
    expect(fn () => $landing->update(['inputs' => ['changed' => true]]))->toThrow(LogicException::class);
    $landing->refresh();
    expect(fn () => $landing->update(['package' => ['changed' => true]]))->toThrow(LogicException::class);
    $landing->refresh();
    expect($landing->toJson())->not->toContain($landing->review_token, 'Perform an independent supplemental review', 'review_token', 'review_prompt')
        ->and($landing->getRawOriginal('review_token'))->not->toBe($landing->review_token)
        ->and($landing->getRawOriginal('review_prompt'))->not->toContain('Perform an independent supplemental review')
        ->and(TaskLandingData::json($landing->inputs))->not->toContain($landing->review_token);
    expect(fn () => $landing->update(['review_assignment' => 'changed']))->toThrow(LogicException::class);
});

it('uses a separate exact package review only after the retained reviewer yields', function () {
    $landing = preparedTaskPackage();
    $review = app(ReviewTaskLanding::class);
    $this->packageReviewerFake->yielded = false;
    expect(fn () => $review->dispatch($landing->id, $landing->package_hash, true, true))->toThrow(LogicException::class, 'not yielded');
    expect($landing->refresh()->review_assignment)->toBeNull();
    $this->packageReviewerFake->yielded = true;
    $review->dispatch($landing->id, $landing->package_hash, true);
    expect($this->packageReviewerFake->prompts)->toBe([]);
    $review->dispatch($landing->id, $landing->package_hash, true, true);
    $review->dispatch($landing->id, $landing->package_hash, true, true);
    expect($this->packageReviewerFake->prompts)->toHaveCount(1)
        ->and($this->packageReviewerFake->prompts[0])->toContain($landing->package_hash, $landing->artifact_sha, $landing->candidate_sha, 'previous final pass is not package approval')
        ->and(TaskAgentDispatch::query()->count())->toBe(1)->and($this->packageRoot->fresh()->status)->toBe(TaskStatus::Completed);
});

it('does not prompt a changed reviewer or changed owner after observation', function (string $change) {
    $landing = preparedTaskPackage();
    if ($change === 'identity') {
        $this->packageReviewerFake->changed = true;
    } else {
        $this->packageReviewerFake->duringObserve = fn () => $this->packageWorkspace->update(['attention' => 'New hold.']);
    }
    expect(fn () => app(ReviewTaskLanding::class)->dispatch($landing->id, $landing->package_hash, true, true))->toThrow(LogicException::class)
        ->and($this->packageReviewerFake->prompts)->toBe([])->and($landing->refresh()->review_assignment)->toBeNull();
})->with(['identity', 'ownership']);

it('retains ambiguous prompt intent and accepts only its exact eventual verdict without resending', function () {
    $landing = preparedTaskPackage();
    $this->packageReviewerFake->losePrompt = true;
    $review = app(ReviewTaskLanding::class);
    expect(fn () => $review->dispatch($landing->id, $landing->package_hash, true, true))->toThrow(RuntimeException::class);
    expect($landing->refresh()->state)->toBe('review_unknown');
    $review->dispatch($landing->id, $landing->package_hash, true, true);
    $review->submit($landing->id, packageReviewReceipt($landing));
    expect($landing->refresh()->state)->toBe('approved')->and($this->packageReviewerFake->prompts)->toHaveCount(1);
});

it('rejects stale and conflicting package review bindings', function (string $field) {
    $landing = dispatchedTaskPackage();
    $receipt = packageReviewReceipt($landing);
    $receipt[$field] = $field === 'session' ? [...$receipt['session'], 'agentId' => 'other'] : 'stale-value';
    expect(fn () => app(ReviewTaskLanding::class)->submit($landing->id, $receipt))->toThrow(LogicException::class)
        ->and($landing->refresh()->review_result)->toBeNull()->and($landing->state)->toBe('sent');
})->with(['token', 'assignment', 'package_hash', 'candidate_sha', 'artifact_sha', 'session', 'verdict']);

it('records immutable verdicts with inert exact replay and leaves original final completion untouched', function (string $verdict) {
    $landing = dispatchedTaskPackage();
    $before = [$this->packageWorkspace->fresh()->toArray(), $this->packageFinal->fresh()->toArray()];
    $receipt = packageReviewReceipt($landing, $verdict);
    $receipt['session'] = array_reverse($receipt['session'], true);
    $review = app(ReviewTaskLanding::class);
    $review->submit($landing->id, $receipt);
    $review->submit($landing->id, array_reverse($receipt, true));
    expect(fn () => $review->submit($landing->id, [...$receipt, 'summary' => 'Different result.']))->toThrow(LogicException::class)
        ->and($landing->refresh()->state)->toBe($verdict === 'pass' ? 'approved' : 'rejected')
        ->and([$this->packageWorkspace->fresh()->toArray(), $this->packageFinal->fresh()->toArray()])->toBe($before)
        ->and($this->packageRoot->fresh()->status)->toBe(TaskStatus::Completed);
    Queue::assertNothingPushed();
})->with(['pass', 'revise', 'blocked']);

it('refuses review completion after candidate or reviewer identity changes', function (bool $candidate) {
    $landing = dispatchedTaskPackage();
    if ($candidate) {
        $this->packageRepositoryFake->changed = true;
    } else {
        $this->packageReviewerFake->changed = true;
    }
    expect(fn () => app(ReviewTaskLanding::class)->submit($landing->id, packageReviewReceipt($landing)))->toThrow(LogicException::class)
        ->and($landing->refresh()->review_result)->toBeNull();
})->with([true, false]);

it('keeps an acknowledged immediate handoff when the later prompt response is lost', function () {
    $landing = preparedTaskPackage();
    $this->packageReviewerFake->duringPrompt = function () use ($landing): void {
        app(ReviewTaskLanding::class)->submit($landing->id, packageReviewReceipt($landing->refresh()));
    };
    $this->packageReviewerFake->losePrompt = true;
    expect(fn () => app(ReviewTaskLanding::class)->dispatch($landing->id, $landing->package_hash, true, true))->toThrow(RuntimeException::class);
    expect($landing->refresh()->state)->toBe('approved')->and($landing->error)->toBeNull();
});

it('previews and applies through explicit CLI commands without queueing task advancement', function () {
    $request = $this->packageDirectory.'/request.json';
    File::put($request, TaskLandingData::json($this->packageRequest));
    expect(Artisan::call('tasks:landing-prepare', ['workspace' => $this->packageWorkspace->id, '--file' => $request, '--exclusive' => true]))->toBe(0);
    $preview = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect(TaskLanding::query()->count())->toBe(0);
    expect(Artisan::call('tasks:landing-prepare', ['workspace' => $this->packageWorkspace->id, '--file' => $request,
        '--exclusive' => true, '--proposal' => $preview['proposal_hash'], '--apply' => true]))->toBe(0);
    $landing = TaskLanding::query()->sole();
    expect(Artisan::call('tasks:landing-review', ['landing' => $landing->id, '--package' => $landing->package_hash, '--exclusive' => true, '--apply' => true]))->toBe(0);
    $landing->refresh();
    $file = $this->packageDirectory.'/verdict.json';
    File::put($file, TaskLandingData::json(packageReviewReceipt($landing)));
    expect(Artisan::call('tasks:landing-submit', ['landing' => $landing->id, '--file' => $file]))->toBe(1);
    $cwd = getcwd();
    try {
        chdir($this->packageWorktree);
        expect(Artisan::call('tasks:landing-submit', ['landing' => $landing->id, '--file' => $file]))->toBe(0);
    } finally {
        chdir($cwd);
    }
    expect($landing->refresh()->state)->toBe('approved');
    Queue::assertNothingPushed();
});

it('requires private-from-creation supplemental handoffs and separates verdict from submission acceptance', function () {
    $landing = dispatchedTaskPackage();
    $before = $landing->getRawOriginal();
    $prompt = app(ReviewTaskLanding::class)->referencePrompt($landing, $landing->review_session, $landing->review_assignment, $landing->review_token);

    expect($prompt)->toContain('Create the handoff privately from the start',
        'fresh mktemp -d directory outside the checkout (mode 0700)',
        'create the empty JSON file with mode 0600 under umask 077',
        'Verify both modes before writing the token', 'do not write it first and chmod afterward',
        'Quote the generated absolute file path in --file',
        escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('artisan')).' tasks:landing-submit '.$landing->id.' --file=',
        'Report your review verdict separately from submission acceptance.',
        'only when the native command confirms', 'submission not confirmed', 'exit code if known',
        'unchanged private handoff', 'only its path and SHA-256', 'Do not retry or resend automatically',
        'Herdr idle/done is not acceptance.', 'Stop and await Commander.')
        ->and($landing->refresh()->getRawOriginal())->toBe($before)
        ->and($this->packageReviewerFake->prompts)->toHaveCount(1);
});

it('reports the bound recorded verdict and preserves the receipt on exact CLI replay', function (string $verdict) {
    $landing = dispatchedTaskPackage();
    $this->packageReviewerFake->yielded = false;
    $receipt = packageReviewReceipt($landing, $verdict);
    $file = $this->packageDirectory.'/recorded-review.json';
    File::put($file, TaskLandingData::json($receipt));
    $fileHash = hash_file('sha256', $file);
    $before = [$this->packageWorkspace->fresh()->getRawOriginal(), $this->packageFinal->fresh()->getRawOriginal(),
        $this->packageRoot->fresh()->getRawOriginal(), $this->packageTask->fresh()->getRawOriginal(), $this->packageRun->fresh()->getRawOriginal()];
    $assignment = [$landing->review_assignment, $landing->review_session, $landing->getRawOriginal('review_token'), $landing->getRawOriginal('review_prompt')];
    $state = $verdict === 'pass' ? 'approved' : 'rejected';
    $cwd = getcwd();
    try {
        chdir($this->packageWorktree);
        foreach ([1, 2] as $submission) {
            expect(Artisan::call('tasks:landing-submit', ['landing' => $landing->id, '--file' => $file]))->toBe(0)
                ->and(Artisan::output())->toContain('Supplemental verdict recorded for landing '.$landing->id.': '.$verdict.' ('.$state.').',
                    'Stop and await Commander.', 'No merge or cleanup was authorized.')
                ->and(Artisan::output())->not->toContain('Submission not confirmed', $receipt['token'], $receipt['summary'], $receipt['evidence']);
            $landing->refresh();
            if ($submission === 1) {
                $recorded = $landing->getRawOriginal();
            } else {
                expect($landing->getRawOriginal())->toBe($recorded);
            }
        }
    } finally {
        chdir($cwd);
    }
    unset($receipt['token']);
    expect($landing->state)->toBe($state)->and($landing->review_result)->toBe($receipt)
        ->and(hash_file('sha256', $file))->toBe($fileHash)
        ->and([$landing->review_assignment, $landing->review_session, $landing->getRawOriginal('review_token'), $landing->getRawOriginal('review_prompt')])->toBe($assignment)
        ->and([$this->packageWorkspace->fresh()->getRawOriginal(), $this->packageFinal->fresh()->getRawOriginal(),
            $this->packageRoot->fresh()->getRawOriginal(), $this->packageTask->fresh()->getRawOriginal(), $this->packageRun->fresh()->getRawOriginal()])->toBe($before)
        ->and(DB::table('task_landing_review_recoveries')->count())->toBe(0)
        ->and(TaskAgentDispatch::query()->count())->toBe(1)->and($this->packageReviewerFake->prompts)->toHaveCount(1);
    Queue::assertNothingPushed();
})->with(['pass', 'revise', 'blocked']);

it('reports provider submission failure without claiming acceptance or retrying', function (bool $uncertainPrompt) {
    $landing = preparedTaskPackage();
    $review = app(ReviewTaskLanding::class);
    $this->packageReviewerFake->losePrompt = $uncertainPrompt;
    if ($uncertainPrompt) {
        expect(fn () => $review->dispatch($landing->id, $landing->package_hash, true, true))->toThrow(RuntimeException::class);
    } else {
        $review->dispatch($landing->id, $landing->package_hash, true, true);
    }
    $landing->refresh();
    $receipt = packageReviewReceipt($landing);
    $file = $this->packageDirectory.'/unconfirmed-review.json';
    File::put($file, TaskLandingData::json($receipt));
    $fileHash = hash_file('sha256', $file);
    $before = [$landing->getRawOriginal(), $this->packageWorkspace->fresh()->getRawOriginal(), $this->packageFinal->fresh()->getRawOriginal(),
        $this->packageRoot->fresh()->getRawOriginal(), $this->packageTask->fresh()->getRawOriginal(), $this->packageRun->fresh()->getRawOriginal()];
    $reads = 0;
    $this->packageIssueFake->duringFetch = function () use (&$reads): never {
        $reads++;
        throw new OrbitIssueProviderFailed('The Hermes Orbit issue provider could not run.', 0, new RuntimeException('private-provider-detail'));
    };
    $cwd = getcwd();
    try {
        chdir($this->packageWorktree);
        expect(Artisan::call('tasks:landing-submit', ['landing' => $landing->id, '--file' => $file]))->toBe(1)
            ->and(Artisan::output())->toContain('The Hermes Orbit issue provider could not run.',
                'Submission not confirmed. Retain the unchanged private handoff and report this failure to Commander.')
            ->and(Artisan::output())->not->toContain('Supplemental verdict recorded', 'private-provider-detail', $receipt['token'], $receipt['summary'], $receipt['evidence']);
    } finally {
        chdir($cwd);
    }
    expect($reads)->toBe(1)->and(hash_file('sha256', $file))->toBe($fileHash)
        ->and($landing->refresh()->review_result)->toBeNull()
        ->and([$landing->getRawOriginal(), $this->packageWorkspace->fresh()->getRawOriginal(), $this->packageFinal->fresh()->getRawOriginal(),
            $this->packageRoot->fresh()->getRawOriginal(), $this->packageTask->fresh()->getRawOriginal(), $this->packageRun->fresh()->getRawOriginal()])->toBe($before)
        ->and(DB::table('task_landing_review_recoveries')->count())->toBe(0)
        ->and(TaskAgentDispatch::query()->count())->toBe(1)->and($this->packageReviewerFake->prompts)->toHaveCount(1);
    Queue::assertNothingPushed();
})->with([false, true]);
