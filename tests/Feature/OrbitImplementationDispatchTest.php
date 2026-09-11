<?php

use App\Delivery\Actions\AdvanceDeliveryAction;
use App\Delivery\Actions\CaptureHerdrEvent;
use App\Delivery\Actions\ConfigureProjectOrchestration;
use App\Delivery\Actions\DispatchOrbitImplementation;
use App\Delivery\Actions\StartOrbitDelivery;
use App\Delivery\Contracts\HerdrRuntime;
use App\Delivery\Contracts\OrbitImplementationRepository;
use App\Delivery\Contracts\OrbitIssueProvider;
use App\Delivery\Contracts\OrbitRepository;
use App\Delivery\Data\CandidateCheck;
use App\Delivery\Data\HerdrAgentIdentifiers;
use App\Delivery\Data\HerdrAgentLaunch;
use App\Delivery\Data\OpenedHerdrWorktree;
use App\Delivery\Data\OrbitDeliveryReservation;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\PreparedIssueSnapshot;
use App\Delivery\Data\PreparedWorktree;
use App\Delivery\Data\VerifiedOrbitImplementationOutcome;
use App\Delivery\Data\VerifiedOrbitPlanningArtifact;
use App\Delivery\Data\VerifiedOrbitPlanningOutcome;
use App\Delivery\Data\VerifiedOrbitPlanningRepository;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ReceiptValidationStatus;
use App\Delivery\Exceptions\OrbitImplementationDispatchFailed;
use App\Delivery\Workflow\IdempotencyKey;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Jobs\AdvanceDelivery;
use App\Jobs\DispatchOrbitImplementation as DispatchImplementationJob;
use App\Models\AgentDispatch;
use App\Models\PhaseRun;
use App\Models\Receipt;
use App\Projects\SharedKnowledgeProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

final class ImplementationDispatchRepository implements OrbitRepository
{
    /** @var list<string> */
    public array $calls = [];

    public mixed $reservationHandle = null;

    public ?Closure $afterReserve = null;

    public ?Closure $afterFinalVerification = null;

    private int $artifactVerifications = 0;

    public function reserveDelivery(OrbitProjectConfig $config, string $issueKey): OrbitDeliveryReservation
    {
        $this->calls[] = 'reserve';
        $handle = tmpfile();

        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException('Could not reserve the implementation delivery.');
        }

        $this->reservationHandle = $handle;

        if ($this->afterReserve instanceof Closure) {
            ($this->afterReserve)();
        }

        return new OrbitDeliveryReservation($handle, 'implementation-dispatch.lock');
    }

    public function prepareWorktree(OrbitProjectConfig $config, string $issueKey): PreparedWorktree
    {
        throw new LogicException('Not used by this test.');
    }

    public function checkCandidate(OrbitProjectConfig $config, PreparedWorktree $worktree): CandidateCheck
    {
        throw new LogicException('Not used by this test.');
    }

    public function verifyPlanningHandoff(
        OrbitProjectConfig $config,
        PreparedWorktree $worktree,
        CandidateCheck $candidate,
        PreparedIssueSnapshot $snapshot,
    ): VerifiedOrbitPlanningRepository {
        throw new LogicException('Not used by this test.');
    }

    public function verifyPlanningArtifact(
        OrbitProjectConfig $config,
        PreparedWorktree $worktree,
        string $issueKey,
        string $artifactSha,
        string $expectedVerdict,
    ): VerifiedOrbitPlanningArtifact {
        $this->calls[] = 'verify-artifact';
        $this->artifactVerifications++;

        expect($worktree->headSha)->toBe(str_repeat('b', 40))
            ->and($artifactSha)->toBe(str_repeat('f', 40))
            ->and($expectedVerdict)->toBe('PASS');

        if ($this->artifactVerifications === 2 && $this->afterFinalVerification instanceof Closure) {
            ($this->afterFinalVerification)();
        }

        return new VerifiedOrbitPlanningArtifact($artifactSha, str_repeat('6', 64));
    }

    public function verifyPlanningOutcome(
        OrbitProjectConfig $config,
        PreparedWorktree $startupWorktree,
        PreparedIssueSnapshot $snapshot,
        string $candidateSha,
        ?string $artifactSha,
    ): VerifiedOrbitPlanningOutcome {
        $this->calls[] = 'verify-candidate';

        expect($startupWorktree->headSha)->toBe(str_repeat('a', 40))
            ->and($candidateSha)->toBe(str_repeat('b', 40))
            ->and($artifactSha)->toBeNull();

        return new VerifiedOrbitPlanningOutcome($candidateSha, str_repeat('c', 40), null, null);
    }

    public function writeIssueSnapshot(
        OrbitProjectConfig $config,
        PreparedWorktree $worktree,
        OrbitIssueSnapshot $snapshot,
    ): PreparedIssueSnapshot {
        throw new LogicException('Not used by this test.');
    }

    public function verifyIssueSnapshot(
        OrbitProjectConfig $config,
        PreparedWorktree $worktree,
        PreparedIssueSnapshot $snapshot,
    ): void {
        throw new LogicException('Not used by this test.');
    }

    public function reservationIsHeld(): bool
    {
        return is_resource($this->reservationHandle);
    }
}

final class ImplementationDispatchIssueProvider implements OrbitIssueProvider
{
    public int $calls = 0;

    public function __construct(public OrbitIssueSnapshot $snapshot) {}

    public function fetch(string $issueId, string $issueKey): OrbitIssueSnapshot
    {
        $this->calls++;

        return $this->snapshot;
    }
}

final class ImplementationCorrectionVerifier implements OrbitImplementationRepository
{
    public int $calls = 0;

    public function verifyImplementationOutcome(
        OrbitProjectConfig $config,
        PreparedWorktree $startupWorktree,
        PreparedIssueSnapshot $snapshot,
        string $reviewedCandidateSha,
        string $candidateSha,
        string $artifactSha,
        string $gateReceiptPath,
        string $pullRequestBody,
    ): VerifiedOrbitImplementationOutcome {
        $this->calls++;

        expect($startupWorktree->headSha)->toBe(str_repeat('a', 40))
            ->and($reviewedCandidateSha)->toBe(str_repeat('b', 40))
            ->and($candidateSha)->toBe(str_repeat('c', 40))
            ->and($artifactSha)->toBe(str_repeat('d', 40));

        return new VerifiedOrbitImplementationOutcome(
            candidateSha: $candidateSha,
            treeSha: str_repeat('e', 40),
            artifactSha: $artifactSha,
            gateReceiptPath: $gateReceiptPath,
            pullRequestBodyHash: hash('sha256', $pullRequestBody),
            flow: 'discovery',
        );
    }
}

final class ImplementationDispatchHerdrRuntime implements HerdrRuntime
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<string> */
    public array $prompts = [];

    public ?string $failure = null;

    public ?Closure $beforePromptReturn = null;

    public HerdrAgentIdentifiers $agent;

    public function openWorktree(string $repositoryPath, string $worktreePath, ?string $label = null): OpenedHerdrWorktree
    {
        throw new LogicException('Implementation must reuse the retained Builder.');
    }

    public function splitPane(string $paneId, string $workingDirectory): HerdrAgentIdentifiers
    {
        throw new LogicException('Implementation must reuse the retained Builder.');
    }

    public function startAgent(string $paneId, string $name, ?HerdrAgentLaunch $launch = null): HerdrAgentIdentifiers
    {
        throw new LogicException('Implementation must reuse the retained Builder.');
    }

    public function promptAgent(string $name, string $prompt): HerdrAgentIdentifiers
    {
        $this->calls[] = 'prompt';
        $this->prompts[] = $prompt;

        if ($this->failure === 'prompt') {
            throw new RuntimeException('Prompt outcome is unknown.');
        }

        if ($this->beforePromptReturn instanceof Closure) {
            ($this->beforePromptReturn)();
        }

        return implementationDispatchAgent($this->agent->workingDirectory, 'working', 42);
    }

    public function getAgent(string $name): HerdrAgentIdentifiers
    {
        $this->calls[] = 'get';

        if ($this->failure === 'get') {
            throw new RuntimeException('Agent lookup unavailable.');
        }

        return $this->agent;
    }
}

function implementationDispatchAgent(
    ?string $cwd,
    string $status,
    int $sequence = 41,
    string $pane = 'builder-pane',
): HerdrAgentIdentifiers {
    return new HerdrAgentIdentifiers(
        'workspace-1',
        'tab-1',
        $pane,
        'builder-terminal',
        'builder-agent-id',
        'orb-234-loop-builder',
        $sequence,
        $cwd,
        $status,
    );
}

beforeEach(function () {
    $this->base = storage_path('framework/testing/orbit-implementation-dispatch-'.bin2hex(random_bytes(4)));
    $projects = $this->base.'/projects';
    File::makeDirectory($projects, 0755, true);
    config()->set('commander.projects_path', $projects);
    config()->set('herdr.session', 'orbit');
    app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
    $project = app(ConfigureProjectOrchestration::class)->handle('orbit', [
        'type' => 'orbit',
        'repository' => '/home/nckrtl/orbit',
        'worktreeRoot' => '/fast/worktrees/orbit',
        'herdrSession' => 'orbit',
        'concurrency' => 1,
        'defaultFlow' => 'discovery',
    ]);
    $this->worktree = '/fast/worktrees/orbit/orb-234';
    $this->delivery = app(StartOrbitDelivery::class)->handle(
        $project,
        verifiedOrbitIssueSnapshot(
            '11111111-2222-4333-8444-555555555555',
            'ORB-234',
            $this->worktree.'/.loop/issue.json',
        ),
        $this->worktree,
        new CandidateCheck(
            '/home/nckrtl/orbit/.git/orbit-checks/'.str_repeat('a', 40).'/startup/result.json',
            str_repeat('a', 40),
            str_repeat('9', 40),
        ),
    );
    $planning = PhaseRun::sole();
    $this->builder = AgentDispatch::query()->create([
        'phase_run_id' => $planning->id,
        'agent_role' => OrbitFeatureWorkflow::PLANNING_AGENT_ROLE,
        'idempotency_key' => 'implementation-builder',
        'herdr_session' => 'orbit',
        'herdr_workspace_id' => 'workspace-1',
        'herdr_tab_id' => 'tab-1',
        'herdr_pane_id' => 'builder-pane',
        'herdr_terminal_id' => 'builder-terminal',
        'herdr_agent_id' => 'builder-agent-id',
        'herdr_agent_name' => 'orb-234-loop-builder',
        'prompt_name' => 'orbit_planning',
        'prompt_version' => 1,
        'prompt_hash' => str_repeat('1', 64),
        'status' => AgentDispatchStatus::Settled,
        'dispatched_at' => now(),
        'settled_at' => now(),
    ]);
    $planningPayload = [
        'kind' => 'orbit_planning', 'schema_version' => 1,
        'delivery_id' => $this->delivery->id, 'dispatch_id' => $this->builder->id,
        'issue_key' => 'ORB-234', 'phase' => OrbitFeatureWorkflow::INITIAL_PHASE,
        'attempt' => 1, 'result' => 'ready', 'worktree' => $this->worktree,
        'candidate_sha' => str_repeat('b', 40),
        'handoff_path' => '.loop/runtime/planning-handoff.md', 'handoff' => 'Review this plan.',
        'artifact_sha' => str_repeat('d', 40), 'plan_sha256' => str_repeat('e', 64),
    ];
    $planningReceipt = Receipt::query()->create([
        'phase_run_id' => $planning->id, 'kind' => 'orbit_planning', 'schema_version' => 1,
        'payload' => $planningPayload,
        'payload_hash' => hash('sha256', json_encode($planningPayload, JSON_THROW_ON_ERROR)),
        'candidate_sha' => str_repeat('b', 40), 'validation_status' => ReceiptValidationStatus::Valid,
        'captured_at' => now(), 'validated_at' => now(),
    ]);
    $planning->forceFill([
        'status' => PhaseRunStatus::Completed,
        'output' => ['receipt_id' => $planningReceipt->id, 'result' => 'ready'],
        'started_at' => now(), 'finished_at' => now(),
    ])->save();
    $review = PhaseRun::query()->create([
        'delivery_id' => $this->delivery->id, 'phase_name' => OrbitFeatureWorkflow::PLAN_REVIEW_PHASE,
        'attempt' => 1, 'status' => PhaseRunStatus::Completed,
        'input' => ['planning_receipt_id' => $planningReceipt->id, 'planning_receipt' => $planningPayload],
        'started_at' => now(), 'finished_at' => now(),
    ]);
    $reviewer = AgentDispatch::query()->create([
        'phase_run_id' => $review->id, 'agent_role' => OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE,
        'idempotency_key' => 'implementation-reviewer', 'herdr_session' => 'orbit',
        'herdr_workspace_id' => 'workspace-1', 'herdr_tab_id' => 'tab-1',
        'herdr_pane_id' => 'review-pane', 'herdr_terminal_id' => 'review-terminal',
        'herdr_agent_id' => 'review-agent-id', 'herdr_agent_name' => 'orb-234-loop-plan-review',
        'prompt_name' => 'orbit_plan_review', 'prompt_version' => 1,
        'prompt_hash' => str_repeat('2', 64), 'status' => AgentDispatchStatus::Settled,
        'dispatched_at' => now(), 'settled_at' => now(),
    ]);
    $reviewPayload = [
        'kind' => 'orbit_plan_review', 'schema_version' => 1,
        'delivery_id' => $this->delivery->id, 'dispatch_id' => $reviewer->id,
        'issue_key' => 'ORB-234', 'phase' => OrbitFeatureWorkflow::PLAN_REVIEW_PHASE,
        'attempt' => 1, 'result' => 'pass', 'worktree' => $this->worktree,
        'candidate_sha' => str_repeat('b', 40),
        'handoff_path' => '.loop/runtime/plan-review-handoff.md',
        'handoff' => 'The plan is approved for implementation.',
        'artifact_sha' => str_repeat('f', 40), 'plan_sha256' => str_repeat('6', 64),
    ];
    $this->reviewReceipt = Receipt::query()->create([
        'phase_run_id' => $review->id, 'kind' => 'orbit_plan_review', 'schema_version' => 1,
        'payload' => $reviewPayload,
        'payload_hash' => hash('sha256', json_encode($reviewPayload, JSON_THROW_ON_ERROR)),
        'candidate_sha' => str_repeat('b', 40), 'validation_status' => ReceiptValidationStatus::Valid,
        'captured_at' => now(), 'validated_at' => now(),
    ]);
    $review->forceFill(['output' => ['receipt_id' => $this->reviewReceipt->id, 'result' => 'pass']])->save();
    $this->implementation = PhaseRun::query()->create([
        'delivery_id' => $this->delivery->id, 'phase_name' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        'attempt' => 1, 'status' => PhaseRunStatus::Pending,
        'input' => ['plan_review_receipt_id' => $this->reviewReceipt->id, 'plan_review_receipt' => $reviewPayload],
    ]);
    $this->dispatch = AgentDispatch::query()->create([
        'phase_run_id' => $this->implementation->id,
        'agent_role' => OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
        'idempotency_key' => IdempotencyKey::forDispatch(
            $this->delivery->id,
            OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
            1,
            OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
        )->value,
        'herdr_agent_name' => 'orb-234-loop-builder', 'prompt_name' => 'orbit_implementation',
        'prompt_version' => 1, 'prompt_hash' => str_repeat('0', 64),
        'status' => AgentDispatchStatus::Pending,
    ]);
    $this->delivery->forceFill([
        'current_phase' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        'candidate_sha' => str_repeat('b', 40), 'status' => DeliveryStatus::Queued,
    ])->save();
    $payload = [
        'id' => '11111111-2222-4333-8444-555555555555', 'identifier' => 'ORB-234',
        'state' => ['id' => 'state-1', 'name' => 'In Progress', 'type' => 'started'],
    ];
    $this->repository = new ImplementationDispatchRepository;
    $this->issues = new ImplementationDispatchIssueProvider(new OrbitIssueSnapshot(
        $payload['id'], $payload['identifier'], $payload, str_repeat('d', 64),
    ));
    $this->herdr = new ImplementationDispatchHerdrRuntime;
    $this->implementationVerifier = new ImplementationCorrectionVerifier;
    $this->herdr->agent = implementationDispatchAgent($this->worktree, 'done');
    app()->instance(OrbitRepository::class, $this->repository);
    app()->instance(OrbitImplementationRepository::class, $this->implementationVerifier);
    app()->instance(OrbitIssueProvider::class, $this->issues);
    app()->instance(HerdrRuntime::class, $this->herdr);
    Queue::fake();
});

afterEach(fn () => File::deleteDirectory($this->base));

function promoteImplementationDispatchToSecondReview(object $test): void
{
    $firstReview = $test->reviewReceipt->phaseRun;
    $firstReviewPayload = [...$test->reviewReceipt->payload, 'result' => 'fix'];
    DB::table('receipts')->where('id', $test->reviewReceipt->id)->update([
        'payload' => json_encode($firstReviewPayload, JSON_THROW_ON_ERROR),
        'payload_hash' => hash('sha256', json_encode($firstReviewPayload, JSON_THROW_ON_ERROR)),
    ]);
    $test->reviewReceipt->refresh();
    $firstReview->forceFill([
        'output' => ['receipt_id' => $test->reviewReceipt->id, 'result' => 'fix'],
    ])->save();

    $planning = PhaseRun::query()->create([
        'delivery_id' => $test->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::INITIAL_PHASE,
        'attempt' => 2,
        'status' => PhaseRunStatus::Completed,
        'input' => [
            'plan_review_receipt_id' => $test->reviewReceipt->id,
            'plan_review_receipt' => $firstReviewPayload,
        ],
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    $test->correctionBuilder = AgentDispatch::query()->create([
        'phase_run_id' => $planning->id,
        'agent_role' => OrbitFeatureWorkflow::PLANNING_AGENT_ROLE,
        'idempotency_key' => 'implementation-correction-builder',
        'herdr_session' => 'orbit',
        'herdr_workspace_id' => 'workspace-1',
        'herdr_tab_id' => 'tab-1',
        'herdr_pane_id' => 'builder-pane',
        'herdr_terminal_id' => 'builder-terminal',
        'herdr_agent_id' => 'builder-agent-id',
        'herdr_agent_name' => 'orb-234-loop-builder',
        'prompt_name' => 'orbit_planning_correction',
        'prompt_version' => 1,
        'prompt_hash' => str_repeat('3', 64),
        'status' => AgentDispatchStatus::Settled,
        'dispatched_at' => now(),
        'settled_at' => now(),
    ]);
    $planningPayload = [
        'kind' => 'orbit_planning', 'schema_version' => 1,
        'delivery_id' => $test->delivery->id, 'dispatch_id' => $test->correctionBuilder->id,
        'issue_key' => 'ORB-234', 'phase' => OrbitFeatureWorkflow::INITIAL_PHASE,
        'attempt' => 2, 'result' => 'ready', 'worktree' => $test->worktree,
        'candidate_sha' => str_repeat('b', 40),
        'handoff_path' => '.loop/runtime/planning-correction-handoff.md',
        'handoff' => 'The plan-review findings were corrected.',
        'artifact_sha' => str_repeat('d', 40), 'plan_sha256' => str_repeat('e', 64),
    ];
    $planningReceipt = Receipt::query()->create([
        'phase_run_id' => $planning->id, 'kind' => 'orbit_planning', 'schema_version' => 1,
        'payload' => $planningPayload,
        'payload_hash' => hash('sha256', json_encode($planningPayload, JSON_THROW_ON_ERROR)),
        'candidate_sha' => str_repeat('b', 40), 'validation_status' => ReceiptValidationStatus::Valid,
        'captured_at' => now(), 'validated_at' => now(),
    ]);
    $planning->forceFill([
        'output' => ['receipt_id' => $planningReceipt->id, 'result' => 'ready'],
    ])->save();
    $review = PhaseRun::query()->create([
        'delivery_id' => $test->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::PLAN_REVIEW_PHASE,
        'attempt' => 2,
        'status' => PhaseRunStatus::Completed,
        'input' => [
            'planning_receipt_id' => $planningReceipt->id,
            'planning_receipt' => $planningPayload,
        ],
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    $reviewer = AgentDispatch::query()->create([
        'phase_run_id' => $review->id,
        'agent_role' => OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE,
        'idempotency_key' => 'implementation-reviewer-2',
        'herdr_agent_name' => 'orb-234-loop-plan-review',
        'prompt_name' => 'orbit_plan_review',
        'prompt_version' => 1,
        'prompt_hash' => str_repeat('4', 64),
        'status' => AgentDispatchStatus::Settled,
        'settled_at' => now(),
    ]);
    $reviewPayload = [
        'kind' => 'orbit_plan_review', 'schema_version' => 1,
        'delivery_id' => $test->delivery->id, 'dispatch_id' => $reviewer->id,
        'issue_key' => 'ORB-234', 'phase' => OrbitFeatureWorkflow::PLAN_REVIEW_PHASE,
        'attempt' => 2, 'result' => 'pass', 'worktree' => $test->worktree,
        'candidate_sha' => str_repeat('b', 40),
        'handoff_path' => '.loop/runtime/plan-review-handoff.md',
        'handoff' => 'The corrected plan is approved.',
        'artifact_sha' => str_repeat('f', 40), 'plan_sha256' => str_repeat('6', 64),
    ];
    $reviewReceipt = Receipt::query()->create([
        'phase_run_id' => $review->id, 'kind' => 'orbit_plan_review', 'schema_version' => 1,
        'payload' => $reviewPayload,
        'payload_hash' => hash('sha256', json_encode($reviewPayload, JSON_THROW_ON_ERROR)),
        'candidate_sha' => str_repeat('b', 40), 'validation_status' => ReceiptValidationStatus::Valid,
        'captured_at' => now(), 'validated_at' => now(),
    ]);
    $review->forceFill(['output' => ['receipt_id' => $reviewReceipt->id, 'result' => 'pass']])->save();
    $test->implementation->forceFill(['input' => [
        'plan_review_receipt_id' => $reviewReceipt->id,
        'plan_review_receipt' => $reviewPayload,
    ]])->save();
}

function promoteImplementationToMergeConflictCorrection(object $test): void
{
    app(DispatchOrbitImplementation::class)->handle($test->delivery->id);
    $test->dispatch->forceFill([
        'status' => AgentDispatchStatus::Settled,
        'settled_at' => now(),
    ])->save();
    $test->implementationBody = implode("\n", [
        'Issue: ORB-234',
        'Candidate: '.str_repeat('c', 40),
        'Artifact: '.str_repeat('d', 40),
        'Flow: discovery',
        'Builder gate: passed (/home/nckrtl/orbit/.git/orbit-checks/gate/result.json)',
    ]);
    $test->implementationPayload = [
        'kind' => 'orbit_implementation', 'schema_version' => 1,
        'delivery_id' => $test->delivery->id, 'dispatch_id' => $test->dispatch->id,
        'issue_key' => 'ORB-234', 'phase' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        'attempt' => 1, 'result' => 'ready', 'worktree' => $test->worktree,
        'reviewed_candidate_sha' => str_repeat('b', 40),
        'candidate_sha' => str_repeat('c', 40),
        'handoff_path' => '.loop/runtime/implementation-handoff.md',
        'handoff' => 'The candidate is ready but conflicts with main.',
        'artifact_sha' => str_repeat('d', 40),
        'gate_receipt_path' => '/home/nckrtl/orbit/.git/orbit-checks/gate/result.json',
        'pull_request_body_path' => '.loop/runtime/pull-request-body.md',
        'pull_request_body' => $test->implementationBody,
        'pull_request_body_sha256' => hash('sha256', $test->implementationBody),
        'flow' => 'discovery',
    ];
    $test->implementationReceipt = Receipt::query()->create([
        'phase_run_id' => $test->implementation->id,
        'kind' => 'orbit_implementation',
        'schema_version' => 1,
        'payload' => $test->implementationPayload,
        'payload_hash' => hash('sha256', json_encode($test->implementationPayload, JSON_THROW_ON_ERROR)),
        'candidate_sha' => str_repeat('c', 40),
        'validation_status' => ReceiptValidationStatus::Valid,
        'captured_at' => now(),
        'validated_at' => now(),
    ]);
    $test->implementation->forceFill([
        'status' => PhaseRunStatus::Completed,
        'output' => [
            'receipt_id' => $test->implementationReceipt->id,
            'result' => 'ready',
            'pull_request_number' => 42,
            'pull_request_url' => 'https://github.com/nckrtl/orbit/pull/42',
            'mergeable' => false,
        ],
        'finished_at' => now(),
    ])->save();
    $test->correction = PhaseRun::query()->create([
        'delivery_id' => $test->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        'attempt' => 2,
        'status' => PhaseRunStatus::Pending,
        'input' => [
            'implementation_receipt_id' => $test->implementationReceipt->id,
            'implementation_receipt' => $test->implementationPayload,
            'pull_request' => [
                'number' => 42,
                'url' => 'https://github.com/nckrtl/orbit/pull/42',
                'mergeable' => false,
            ],
        ],
    ]);
    $test->correctionDispatch = AgentDispatch::query()->create([
        'phase_run_id' => $test->correction->id,
        'agent_role' => OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
        'idempotency_key' => IdempotencyKey::forDispatch(
            $test->delivery->id,
            OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
            2,
            OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
        )->value,
        'herdr_agent_name' => 'orb-234-loop-builder',
        'prompt_name' => 'orbit_implementation_correction',
        'prompt_version' => 1,
        'prompt_hash' => str_repeat('0', 64),
        'status' => AgentDispatchStatus::Pending,
    ]);
    $test->delivery->refresh()->forceFill([
        'candidate_sha' => str_repeat('c', 40),
        'pull_request_number' => 42,
        'pull_request_url' => 'https://github.com/nckrtl/orbit/pull/42',
        'current_phase' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        'status' => DeliveryStatus::Queued,
    ])->save();
    $test->repository->calls = [];
    $test->issues->calls = 0;
    $test->herdr->calls = [];
    $test->herdr->prompts = [];
    $test->implementationVerifier->calls = 0;
}

it('queues the dedicated implementation dispatcher', function () {
    expect(app(AdvanceDeliveryAction::class)->handle($this->delivery->id))->toBeFalse();
    Queue::assertPushed(DispatchImplementationJob::class, 1);
});

it('queues the retained-Builder dispatcher for a merge-conflict correction', function () {
    promoteImplementationToMergeConflictCorrection($this);
    Queue::fake();

    expect(app(AdvanceDeliveryAction::class)->handle($this->delivery->id))->toBeFalse();

    Queue::assertPushed(DispatchImplementationJob::class, 1);
});

it('prompts the exact retained Builder with the immutable passing review', function () {
    $dispatch = app(DispatchOrbitImplementation::class)->handle($this->delivery->id);

    expect($this->herdr->calls)->toBe(['get', 'prompt'])
        ->and($this->repository->calls)->toBe([
            'reserve', 'verify-candidate', 'verify-artifact', 'verify-candidate', 'verify-artifact',
        ])
        ->and($this->issues->calls)->toBe(2)
        ->and($dispatch->status)->toBe(AgentDispatchStatus::Waiting)
        ->and($dispatch->herdr_pane_id)->toBe($this->builder->herdr_pane_id)
        ->and($dispatch->herdr_agent_id)->toBe($this->builder->herdr_agent_id)
        ->and($dispatch->state_change_seq)->toBe(42)
        ->and($this->builder->fresh()->status)->toBe(AgentDispatchStatus::Settled)
        ->and($this->implementation->fresh()->status)->toBe(PhaseRunStatus::Running)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::WaitingForAgent)
        ->and($this->repository->reservationIsHeld())->toBeFalse()
        ->and($this->herdr->prompts[0])->toContain(
            'Implement the independently approved plan',
            "delivery:submit-orbit-implementation-receipt {$this->implementation->id} {$this->dispatch->id} --result=ready",
            json_encode($this->reviewReceipt->payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        )
        ->and($dispatch->prompt_hash)->toBe(hash('sha256', $this->herdr->prompts[0]));

    app(DispatchOrbitImplementation::class)->handle($this->delivery->id);
    expect($this->herdr->calls)->toBe(['get', 'prompt'])
        ->and(AgentDispatch::where('herdr_pane_id', 'builder-pane')->count())->toBe(2);
});

it('prompts the exact retained Builder to correct verified merge conflicts', function () {
    promoteImplementationToMergeConflictCorrection($this);

    $dispatch = app(DispatchOrbitImplementation::class)->handle($this->delivery->id);

    expect($this->herdr->calls)->toBe(['get', 'prompt'])
        ->and($this->repository->calls)->toBe(['reserve'])
        ->and($this->implementationVerifier->calls)->toBe(2)
        ->and($this->issues->calls)->toBe(2)
        ->and($dispatch->id)->toBe($this->correctionDispatch->id)
        ->and($dispatch->status)->toBe(AgentDispatchStatus::Waiting)
        ->and($dispatch->herdr_pane_id)->toBe($this->builder->herdr_pane_id)
        ->and($this->correction->fresh()->status)->toBe(PhaseRunStatus::Running)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::WaitingForAgent)
        ->and($this->repository->reservationIsHeld())->toBeFalse()
        ->and($this->herdr->prompts[0])->toContain(
            "Resolve the published candidate's actual merge conflicts with main",
            'Do not restart preflight merely because',
            "delivery:submit-orbit-implementation-receipt {$this->correction->id} {$this->correctionDispatch->id} --result=ready",
            json_encode($this->implementationPayload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'https://github.com/nckrtl/orbit/pull/42',
        )
        ->and($dispatch->prompt_hash)->toBe(hash('sha256', $this->herdr->prompts[0]));

    app(DispatchOrbitImplementation::class)->handle($this->delivery->id);
    expect($this->herdr->calls)->toBe(['get', 'prompt']);
});

it('rejects a correction whose implementation dispatch did not retain the exact Builder', function () {
    promoteImplementationToMergeConflictCorrection($this);
    $this->dispatch->forceFill(['herdr_pane_id' => 'replacement-pane'])->save();

    expect(fn () => app(DispatchOrbitImplementation::class)->handle($this->delivery->id))
        ->toThrow(OrbitImplementationDispatchFailed::class, 'did not retain the exact implementation Builder');

    expect($this->herdr->calls)->toBe([]);
});

it('rejects correction input changes after taking the controller reservation', function () {
    promoteImplementationToMergeConflictCorrection($this);
    $this->repository->afterReserve = function (): void {
        DB::table('receipts')->where('id', $this->implementationReceipt->id)->update([
            'payload_hash' => str_repeat('0', 64),
        ]);
    };

    expect(fn () => app(DispatchOrbitImplementation::class)->handle($this->delivery->id))
        ->toThrow(OrbitImplementationDispatchFailed::class, 'published implementation receipt');

    expect($this->herdr->calls)->toBe([])
        ->and($this->implementationVerifier->calls)->toBe(0)
        ->and($this->correctionDispatch->fresh()->status)->toBe(AgentDispatchStatus::Pending)
        ->and($this->repository->reservationIsHeld())->toBeFalse();
});

it('accepts a second passing review only when its correction retained the exact Builder', function () {
    promoteImplementationDispatchToSecondReview($this);

    $dispatch = app(DispatchOrbitImplementation::class)->handle($this->delivery->id);

    expect($dispatch->status)->toBe(AgentDispatchStatus::Waiting)
        ->and($dispatch->herdr_agent_id)->toBe($this->builder->herdr_agent_id)
        ->and($this->herdr->prompts[0])->toContain('"attempt": 2');
});

it('rejects second-review provenance from a different correction Builder', function () {
    promoteImplementationDispatchToSecondReview($this);
    $this->correctionBuilder->forceFill(['herdr_pane_id' => 'replacement-pane'])->save();

    expect(fn () => app(DispatchOrbitImplementation::class)->handle($this->delivery->id))
        ->toThrow(OrbitImplementationDispatchFailed::class, 'did not retain the exact Builder');

    expect($this->herdr->calls)->toBe([]);
});

it('correlates retained Builder completion to the implementation dispatch', function () {
    app(DispatchOrbitImplementation::class)->handle($this->delivery->id);
    config()->set('herdr.orchestration.enabled', true);

    $event = app(CaptureHerdrEvent::class)->handle([
        'event' => 'pane.agent_status_changed',
        'data' => ['pane_id' => 'builder-pane', 'workspace_id' => 'workspace-1', 'agent_status' => 'done'],
    ]);

    expect($event?->agent_dispatch_id)->toBe($this->dispatch->id)
        ->and($this->dispatch->fresh()->status)->toBe(AgentDispatchStatus::Settled)
        ->and($this->builder->fresh()->status)->toBe(AgentDispatchStatus::Settled);
    Queue::assertPushed(AdvanceDelivery::class, 1);
});

it('blocks a retained Builder that is not exact and available', function (string $change) {
    $this->herdr->agent = match ($change) {
        'busy' => implementationDispatchAgent($this->worktree, 'working'),
        'worktree' => implementationDispatchAgent('/fast/worktrees/orbit/other', 'done'),
        default => implementationDispatchAgent($this->worktree, 'done', pane: 'other-pane'),
    };

    expect(fn () => app(DispatchOrbitImplementation::class)->handle($this->delivery->id))
        ->toThrow(OrbitImplementationDispatchFailed::class, 'missing, busy, or outside');

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->delivery->fresh()->failure_details['code'])->toBe('retained_builder_unavailable')
        ->and($this->dispatch->fresh()->status)->toBe(AgentDispatchStatus::Failed)
        ->and($this->herdr->calls)->toBe(['get'])
        ->and($this->repository->reservationIsHeld())->toBeFalse();
})->with(['busy', 'worktree', 'identity']);

it('rejects source review changes after taking the controller reservation', function () {
    $this->repository->afterReserve = function (): void {
        DB::table('receipts')->where('id', $this->reviewReceipt->id)->update([
            'payload_hash' => str_repeat('0', 64),
        ]);
    };

    expect(fn () => app(DispatchOrbitImplementation::class)->handle($this->delivery->id))
        ->toThrow(OrbitImplementationDispatchFailed::class, 'passing plan review');

    expect($this->herdr->calls)->toBe([])
        ->and($this->repository->calls)->toBe(['reserve'])
        ->and($this->dispatch->fresh()->status)->toBe(AgentDispatchStatus::Pending)
        ->and($this->repository->reservationIsHeld())->toBeFalse();
});

it('retries when the retained Builder cannot be inspected', function () {
    $this->herdr->failure = 'get';

    expect(fn () => app(DispatchOrbitImplementation::class)->handle($this->delivery->id))
        ->toThrow(OrbitImplementationDispatchFailed::class, 'could not be inspected');

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Preparing)
        ->and($this->dispatch->fresh()->status)->toBe(AgentDispatchStatus::Pending)
        ->and($this->herdr->calls)->toBe(['get'])
        ->and($this->repository->reservationIsHeld())->toBeFalse();
});

it('blocks an interrupted starting implementation instead of replaying it', function () {
    $this->herdr->failure = 'get';

    expect(fn () => app(DispatchOrbitImplementation::class)->handle($this->delivery->id))
        ->toThrow(OrbitImplementationDispatchFailed::class, 'could not be inspected');

    $this->dispatch->forceFill([
        'status' => AgentDispatchStatus::Starting,
        'error_code' => 'implementation_dispatch_starting',
    ])->save();
    $this->herdr->failure = null;

    expect(fn () => app(DispatchOrbitImplementation::class)->handle($this->delivery->id))
        ->toThrow(OrbitImplementationDispatchFailed::class, 'manual recovery is required');

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->delivery->fresh()->failure_details['code'])->toBe('implementation_dispatch_interrupted')
        ->and($this->herdr->calls)->toBe(['get']);
});

it('blocks a config change after final repository verification', function () {
    $this->repository->afterFinalVerification = function (): void {
        $project = $this->delivery->projectOrchestration;
        $config = $project->config;
        $config['concurrency'] = 2;
        DB::table('project_orchestrations')->where('id', $project->id)->update([
            'config' => json_encode($config, JSON_THROW_ON_ERROR),
        ]);
    };

    expect(fn () => app(DispatchOrbitImplementation::class)->handle($this->delivery->id))
        ->toThrow(OrbitImplementationDispatchFailed::class, 'Final implementation verification failed');

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->delivery->fresh()->failure_details['code'])->toBe('implementation_final_verification_failed')
        ->and($this->dispatch->fresh()->status)->toBe(AgentDispatchStatus::Failed)
        ->and($this->herdr->calls)->toBe(['get'])
        ->and($this->herdr->prompts)->toBeEmpty();
});

it('blocks an ambiguous prompt and never replays it', function () {
    $this->herdr->failure = 'prompt';

    expect(fn () => app(DispatchOrbitImplementation::class)->handle($this->delivery->id))
        ->toThrow(OrbitImplementationDispatchFailed::class, 'will not submit it again automatically');
    expect($this->dispatch->fresh()->status)->toBe(AgentDispatchStatus::Ambiguous)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->herdr->prompts)->toHaveCount(1);

    expect(fn () => app(DispatchOrbitImplementation::class)->handle($this->delivery->id))
        ->toThrow(OrbitImplementationDispatchFailed::class, 'not eligible');
    expect($this->herdr->prompts)->toHaveCount(1);
});

it('preserves settlement while the implementation prompt returns', function () {
    config()->set('herdr.orchestration.enabled', true);
    $this->herdr->beforePromptReturn = function (): void {
        app(CaptureHerdrEvent::class)->handle([
            'event' => 'pane.agent_status_changed',
            'data' => ['pane_id' => 'builder-pane', 'workspace_id' => 'workspace-1', 'agent_status' => 'done'],
        ]);
    };

    $dispatch = app(DispatchOrbitImplementation::class)->handle($this->delivery->id);

    expect($dispatch->status)->toBe(AgentDispatchStatus::Settled)
        ->and($dispatch->error_code)->toBeNull()
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::WaitingForAgent);
    Queue::assertPushed(AdvanceDelivery::class, 1);
});

it('preserves correction settlement while the retained-Builder prompt returns', function () {
    promoteImplementationToMergeConflictCorrection($this);
    config()->set('herdr.orchestration.enabled', true);
    $this->herdr->beforePromptReturn = function (): void {
        app(CaptureHerdrEvent::class)->handle([
            'event' => 'pane.agent_status_changed',
            'data' => ['pane_id' => 'builder-pane', 'workspace_id' => 'workspace-1', 'agent_status' => 'done'],
        ]);
    };

    $dispatch = app(DispatchOrbitImplementation::class)->handle($this->delivery->id);

    expect($dispatch->id)->toBe($this->correctionDispatch->id)
        ->and($dispatch->status)->toBe(AgentDispatchStatus::Settled)
        ->and($dispatch->error_code)->toBeNull()
        ->and($this->implementation->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::WaitingForAgent);
    Queue::assertPushed(AdvanceDelivery::class, 1);
});

it('bounds the implementation job and preserves blocked recovery on failure', function () {
    $job = new DispatchImplementationJob($this->delivery->id);
    $this->delivery->forceFill([
        'status' => DeliveryStatus::Blocked,
        'failure_details' => ['code' => 'retained_builder_unavailable'],
    ])->save();
    $job->failed(new RuntimeException('Queue exhausted.'));

    expect($job->tries)->toBe(0)
        ->and($job->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'))
        ->and(DispatchImplementationJob::LOCK_SECONDS)->toBeGreaterThan($job->timeout)
        ->and($job->retryUntil() > now())->toBeTrue()
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->delivery->fresh()->failure_details)->toBe(['code' => 'retained_builder_unavailable']);
});

it('fails an active delivery when implementation dispatch retries are exhausted', function () {
    $job = new DispatchImplementationJob($this->delivery->id);
    $this->delivery->forceFill(['status' => DeliveryStatus::Preparing])->save();

    $job->failed(new RuntimeException('Queue exhausted.'));

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Failed)
        ->and($this->delivery->fresh()->failed_at)->not->toBeNull()
        ->and($this->delivery->fresh()->failure_details)->toBe([
            'code' => 'implementation_dispatch_exhausted',
            'message' => 'Queue exhausted.',
        ]);
});

it('releases a contended implementation-dispatch lock for retry', function () {
    $lock = Cache::lock(
        "delivery:implementation-dispatch:{$this->delivery->id}",
        DispatchImplementationJob::LOCK_SECONDS,
    );
    $lock->get();

    try {
        $job = (new DispatchImplementationJob($this->delivery->id))->withFakeQueueInteractions();
        $job->handle(app(DispatchOrbitImplementation::class));
        $job->assertReleased(1);
    } finally {
        $lock->release();
    }
});
