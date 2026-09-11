<?php

use App\Delivery\Actions\AdvanceDeliveryAction;
use App\Delivery\Actions\AdvanceOrbitLanding;
use App\Delivery\Actions\ConfigureProjectOrchestration;
use App\Delivery\Actions\StartOrbitDelivery;
use App\Delivery\Contracts\OrbitActiveIssueProvider;
use App\Delivery\Contracts\OrbitImplementationRepository;
use App\Delivery\Contracts\OrbitMainCorrectnessInspector;
use App\Delivery\Contracts\OrbitPullRequestLandingGateway;
use App\Delivery\Contracts\OrbitRepository;
use App\Delivery\Data\ApprovedOrbitPullRequest;
use App\Delivery\Data\CandidateCheck;
use App\Delivery\Data\MergedOrbitPullRequest;
use App\Delivery\Data\OrbitDeliveryReservation;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitLandingReservation;
use App\Delivery\Data\OrbitMainCorrectness;
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
use App\Delivery\Exceptions\OrbitIssueContractChanged;
use App\Delivery\Exceptions\OrbitLandingAdvancementFailed;
use App\Delivery\Exceptions\OrbitPullRequestLandingFailed;
use App\Delivery\Workflow\IdempotencyKey;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Jobs\AdvanceOrbitLanding as AdvanceLandingJob;
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

final class LandingRepository implements OrbitRepository
{
    public int $transactionLevel = 0;

    public int $calls = 0;

    public mixed $reservationHandle = null;

    public ?Closure $afterReserve = null;

    public function reserveDelivery(OrbitProjectConfig $config, string $issueKey): OrbitDeliveryReservation
    {
        expect(DB::transactionLevel())->toBe($this->transactionLevel)
            ->and($issueKey)->toBe('ORB-234');
        $this->calls++;
        $handle = tmpfile();

        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException('Could not reserve landing advancement.');
        }

        $this->reservationHandle = $handle;

        if ($this->afterReserve instanceof Closure) {
            ($this->afterReserve)();
        }

        return new OrbitDeliveryReservation($handle, 'landing-advance.lock');
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
        throw new LogicException('Not used by this test.');
    }

    public function verifyPlanningOutcome(
        OrbitProjectConfig $config,
        PreparedWorktree $startupWorktree,
        PreparedIssueSnapshot $snapshot,
        string $candidateSha,
        ?string $artifactSha,
    ): VerifiedOrbitPlanningOutcome {
        throw new LogicException('Not used by this test.');
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

final class LandingImplementationRepository implements OrbitImplementationRepository
{
    public int $transactionLevel = 0;

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
        expect(DB::transactionLevel())->toBe($this->transactionLevel)
            ->and($snapshot->issueKey)->toBe('ORB-234')
            ->and($reviewedCandidateSha)->toBe(str_repeat('a', 40))
            ->and($candidateSha)->toBe(str_repeat('b', 40))
            ->and($artifactSha)->toBe(str_repeat('c', 40));
        $this->calls++;

        return new VerifiedOrbitImplementationOutcome(
            candidateSha: $candidateSha,
            treeSha: str_repeat('7', 40),
            artifactSha: $artifactSha,
            gateReceiptPath: $gateReceiptPath,
            pullRequestBodyHash: hash('sha256', $pullRequestBody),
            flow: 'discovery',
        );
    }
}

final class LandingIssues implements OrbitActiveIssueProvider
{
    public int $transactionLevel = 0;

    public int $calls = 0;

    public string $state = 'In Review';

    public mixed $assignee = null;

    public function fetchActive(string $issueId, string $issueKey): OrbitIssueSnapshot
    {
        expect(DB::transactionLevel())->toBe($this->transactionLevel);
        $this->calls++;

        return new OrbitIssueSnapshot(
            $issueId,
            $issueKey,
            [
                'id' => $issueId,
                'identifier' => $issueKey,
                'state' => ['id' => 'review', 'name' => $this->state, 'type' => 'started'],
                'assignee' => $this->assignee,
                'delegate' => ['id' => config('commander.hermes.tom_linear_viewer_id')],
            ],
            str_repeat('d', 64),
        );
    }
}

final class LandingMain implements OrbitMainCorrectnessInspector
{
    public int $transactionLevel = 0;

    public int $calls = 0;

    /** @var array<string, mixed> */
    public array $failures = [];

    public function inspectMainCorrectness(OrbitProjectConfig $config): OrbitMainCorrectness
    {
        expect(DB::transactionLevel())->toBe($this->transactionLevel);
        $this->calls++;

        return new OrbitMainCorrectness(str_repeat('e', 40), $this->failures);
    }
}

final class LandingGateway implements OrbitPullRequestLandingGateway
{
    public int $transactionLevel = 0;

    public int $reserveCalls = 0;

    public int $inspectCalls = 0;

    public int $mergeCalls = 0;

    public int $releaseCalls = 0;

    public bool $owned = true;

    public ?bool $mergeable = true;

    public bool $approvalMismatch = false;

    public int $mergeFailures = 0;

    public int $releaseFailures = 0;

    public function reserve(string $issueId, string $pullRequestUrl): OrbitLandingReservation
    {
        expect(DB::transactionLevel())->toBe($this->transactionLevel);
        $this->reserveCalls++;

        return new OrbitLandingReservation(
            owned: $this->owned,
            issueId: $this->owned ? $issueId : '22222222-3333-4444-8555-666666666666',
            pullRequestUrl: $this->owned ? $pullRequestUrl : 'https://github.com/nckrtl/orbit/pull/41',
            reservedAt: '2026-09-11T12:00:00Z',
        );
    }

    public function inspectApproved(
        int $number,
        string $issueKey,
        string $candidateSha,
        string $pullRequestBody,
        int $publishedReviewId,
    ): ApprovedOrbitPullRequest {
        expect(DB::transactionLevel())->toBe($this->transactionLevel)
            ->and($number)->toBe(42)
            ->and($issueKey)->toBe('ORB-234')
            ->and($publishedReviewId)->toBe(901);
        $this->inspectCalls++;

        return new ApprovedOrbitPullRequest(
            number: $this->approvalMismatch ? 43 : $number,
            url: $this->approvalMismatch
                ? 'https://github.com/nckrtl/orbit/pull/43'
                : 'https://github.com/nckrtl/orbit/pull/42',
            candidateSha: $candidateSha,
            bodyHash: hash('sha256', $pullRequestBody),
            reviewId: $publishedReviewId,
            reviewerLogin: 'tom-nckrtl[bot]',
            mergeable: $this->mergeable,
        );
    }

    public function merge(int $number, string $candidateSha): MergedOrbitPullRequest
    {
        expect(DB::transactionLevel())->toBe($this->transactionLevel);
        $this->mergeCalls++;

        if ($this->mergeFailures > 0) {
            $this->mergeFailures--;

            throw new OrbitPullRequestLandingFailed('The merge outcome is unresolved.');
        }

        return new MergedOrbitPullRequest(
            number: $number,
            url: "https://github.com/nckrtl/orbit/pull/{$number}",
            candidateSha: $candidateSha,
            mergeCommitSha: str_repeat('f', 40),
        );
    }

    public function release(string $issueId, string $pullRequestUrl): void
    {
        expect(DB::transactionLevel())->toBe($this->transactionLevel);
        $this->releaseCalls++;

        if ($this->releaseFailures > 0) {
            $this->releaseFailures--;

            throw new OrbitPullRequestLandingFailed('The reservation release is unresolved.');
        }
    }
}

beforeEach(function () {
    $this->base = storage_path('framework/testing/orbit-landing-'.bin2hex(random_bytes(4)));
    $this->projectsPath = $this->base.'/projects';
    $this->repositoryPath = $this->base.'/repository';
    $this->worktreeRoot = $this->base.'/worktrees';
    $this->worktreePath = $this->worktreeRoot.'/orb-234';
    $this->reviewedSha = str_repeat('a', 40);
    $this->candidateSha = str_repeat('b', 40);
    $this->artifactSha = str_repeat('c', 40);
    $this->gatePath = $this->repositoryPath.'/.git/orbit-checks/'.$this->candidateSha.'/review/result.json';
    $this->body = implode("\n", [
        'Issue: ORB-234',
        'Candidate: '.$this->candidateSha,
        'Artifact: '.$this->artifactSha,
        'Flow: discovery',
        'Builder gate: passed ('.$this->gatePath.')',
        'Approved body.',
    ]);

    File::makeDirectory($this->projectsPath, 0755, true);
    config()->set('commander.projects_path', $this->projectsPath);
    app(SharedKnowledgeProjectRepository::class)->create('orbit', [
        'name' => 'Orbit',
        'status' => 'active',
    ]);
    $project = app(ConfigureProjectOrchestration::class)->handle('orbit', [
        'type' => 'orbit',
        'repository' => $this->repositoryPath,
        'worktreeRoot' => $this->worktreeRoot,
        'herdrSession' => 'orbit',
        'concurrency' => 1,
        'defaultFlow' => 'discovery',
    ]);
    $this->delivery = app(StartOrbitDelivery::class)->handle(
        $project,
        verifiedOrbitIssueSnapshot(
            '11111111-2222-4333-8444-555555555555',
            'ORB-234',
            $this->worktreePath.'/.loop/issue.json',
        ),
        $this->worktreePath,
        new CandidateCheck(
            $this->repositoryPath.'/.git/orbit-checks/'.$this->reviewedSha.'/startup/result.json',
            $this->reviewedSha,
            str_repeat('9', 40),
        ),
    );

    $planning = PhaseRun::sole();
    $planner = landingDispatch($planning, OrbitFeatureWorkflow::PLANNING_AGENT_ROLE, 'planner');
    $planningPayload = [
        'kind' => 'orbit_planning', 'schema_version' => 1,
        'delivery_id' => $this->delivery->id, 'dispatch_id' => $planner->id,
        'issue_key' => 'ORB-234', 'phase' => OrbitFeatureWorkflow::INITIAL_PHASE,
        'attempt' => 1, 'result' => 'ready', 'worktree' => $this->worktreePath,
        'candidate_sha' => $this->reviewedSha,
        'handoff_path' => '.loop/runtime/planning.md', 'handoff' => 'Planning ready.',
        'artifact_sha' => str_repeat('1', 40), 'plan_sha256' => str_repeat('2', 64),
    ];
    $planningReceipt = landingReceipt($planning, 'orbit_planning', $planningPayload);
    landingComplete($planning, [
        'receipt_id' => $planningReceipt->id,
        'result' => 'ready',
    ]);

    $planReview = PhaseRun::query()->create([
        'delivery_id' => $this->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::PLAN_REVIEW_PHASE,
        'attempt' => 1,
        'status' => PhaseRunStatus::Completed,
        'input' => [
            'planning_receipt_id' => $planningReceipt->id,
            'planning_receipt' => $planningPayload,
        ],
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    $planReviewer = landingDispatch($planReview, OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE, 'plan-reviewer');
    $planReviewPayload = [
        'kind' => 'orbit_plan_review', 'schema_version' => 1,
        'delivery_id' => $this->delivery->id, 'dispatch_id' => $planReviewer->id,
        'issue_key' => 'ORB-234', 'phase' => OrbitFeatureWorkflow::PLAN_REVIEW_PHASE,
        'attempt' => 1, 'result' => 'pass', 'worktree' => $this->worktreePath,
        'candidate_sha' => $this->reviewedSha,
        'handoff_path' => '.loop/runtime/plan-review.md', 'handoff' => 'Plan approved.',
        'artifact_sha' => str_repeat('3', 40), 'plan_sha256' => str_repeat('4', 64),
    ];
    $planReviewReceipt = landingReceipt($planReview, 'orbit_plan_review', $planReviewPayload);
    landingComplete($planReview, [
        'receipt_id' => $planReviewReceipt->id,
        'result' => 'pass',
    ]);

    $implementation = PhaseRun::query()->create([
        'delivery_id' => $this->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        'attempt' => 1,
        'status' => PhaseRunStatus::Completed,
        'input' => [
            'plan_review_receipt_id' => $planReviewReceipt->id,
            'plan_review_receipt' => $planReviewPayload,
        ],
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    $builder = landingDispatch($implementation, OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE, 'builder');
    $builder->forceFill([
        'idempotency_key' => IdempotencyKey::forDispatch(
            $this->delivery->id,
            OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
            1,
            OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
        )->value,
        'herdr_agent_name' => 'orb-234-loop-builder',
        'prompt_name' => 'orbit_implementation',
        'prompt_hash' => str_repeat('5', 64),
        'dispatched_at' => now(),
    ])->save();
    $implementationPayload = [
        'kind' => 'orbit_implementation', 'schema_version' => 1,
        'delivery_id' => $this->delivery->id, 'dispatch_id' => $builder->id,
        'issue_key' => 'ORB-234', 'phase' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        'attempt' => 1, 'result' => 'ready', 'worktree' => $this->worktreePath,
        'reviewed_candidate_sha' => $this->reviewedSha,
        'candidate_sha' => $this->candidateSha,
        'handoff_path' => '.loop/runtime/implementation.md', 'handoff' => 'Implementation ready.',
        'artifact_sha' => $this->artifactSha,
        'gate_receipt_path' => $this->gatePath,
        'pull_request_body_path' => '.loop/runtime/pull-request-body.md',
        'pull_request_body' => $this->body,
        'pull_request_body_sha256' => hash('sha256', $this->body),
        'flow' => 'discovery',
    ];
    $this->implementationReceipt = landingReceipt(
        $implementation,
        'orbit_implementation',
        $implementationPayload,
    );
    landingComplete($implementation, [
        'receipt_id' => $this->implementationReceipt->id,
        'result' => 'ready',
        'pull_request_number' => 42,
        'pull_request_url' => 'https://github.com/nckrtl/orbit/pull/42',
        'mergeable' => true,
    ]);

    $review = PhaseRun::query()->create([
        'delivery_id' => $this->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::PR_REVIEW_PHASE,
        'attempt' => 1,
        'status' => PhaseRunStatus::Completed,
        'input' => [
            'implementation_receipt_id' => $this->implementationReceipt->id,
            'implementation_receipt' => $implementationPayload,
            'pull_request' => [
                'number' => 42,
                'url' => 'https://github.com/nckrtl/orbit/pull/42',
                'mergeable' => true,
            ],
        ],
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    $reviewer = landingDispatch($review, OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE, 'pr-reviewer');
    $reviewer->forceFill([
        'idempotency_key' => IdempotencyKey::forDispatch(
            $this->delivery->id,
            OrbitFeatureWorkflow::PR_REVIEW_PHASE,
            1,
            OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE,
        )->value,
        'herdr_agent_name' => 'orb-234-loop-pr-review-1',
        'prompt_name' => 'orbit_pr_review',
        'herdr_session' => 'orbit',
        'herdr_workspace_id' => 'review-workspace',
        'herdr_tab_id' => 'review-tab',
        'herdr_pane_id' => 'review-pane',
        'herdr_terminal_id' => 'review-terminal',
        'herdr_agent_id' => 'review-agent',
        'dispatched_at' => now(),
    ])->save();
    $reviewPrompt = app(OrbitFeatureWorkflow::class)->pullRequestReviewPrompt(
        'ORB-234',
        $this->worktreePath,
        $this->delivery->id,
        $review->id,
        $reviewer->id,
        sprintf(
            '%s %s delivery:submit-orbit-pr-review-receipt %d %d',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('artisan')),
            $review->id,
            $reviewer->id,
        ),
        $implementationPayload,
        $review->input['pull_request'],
    );
    $reviewer->forceFill(['prompt_hash' => hash('sha256', $reviewPrompt)])->save();
    $reviewPayload = [
        'kind' => 'orbit_pr_review', 'schema_version' => 1,
        'delivery_id' => $this->delivery->id, 'dispatch_id' => $reviewer->id,
        'issue_key' => 'ORB-234', 'phase' => OrbitFeatureWorkflow::PR_REVIEW_PHASE,
        'attempt' => 1, 'result' => 'approved', 'worktree' => $this->worktreePath,
        'candidate_sha' => $this->candidateSha,
        'handoff_path' => '.loop/runtime/pr-review.md', 'handoff' => 'Approved.',
        'artifact_sha' => $this->artifactSha,
        'pull_request_body_path' => '.loop/runtime/pull-request-body.md',
        'pull_request_body' => $this->body,
        'pull_request_body_sha256' => hash('sha256', $this->body),
    ];
    $this->reviewReceipt = landingReceipt($review, 'orbit_pr_review', $reviewPayload);
    $this->published = [
        'id' => 901,
        'reviewer_login' => 'tom-nckrtl[bot]',
        'candidate_sha' => $this->candidateSha,
        'state' => 'APPROVED',
        'review_body_sha256' => hash('sha256', 'Approved.'),
        'pull_request_body_sha256' => hash('sha256', $this->body),
    ];
    landingComplete($review, [
        'receipt_id' => $this->reviewReceipt->id,
        'result' => 'approved',
        'published_review' => $this->published,
    ]);

    $this->landing = PhaseRun::query()->create([
        'delivery_id' => $this->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::LANDING_PHASE,
        'attempt' => 1,
        'status' => PhaseRunStatus::Pending,
        'input' => [
            'pr_review_receipt_id' => $this->reviewReceipt->id,
            'pr_review_receipt' => $reviewPayload,
            'implementation_receipt_id' => $this->implementationReceipt->id,
            'implementation_receipt' => $implementationPayload,
            'pull_request' => $review->input['pull_request'],
            'published_review' => $this->published,
        ],
    ]);
    $this->delivery->forceFill([
        'candidate_sha' => $this->candidateSha,
        'pull_request_number' => 42,
        'pull_request_url' => 'https://github.com/nckrtl/orbit/pull/42',
        'current_phase' => OrbitFeatureWorkflow::LANDING_PHASE,
        'status' => DeliveryStatus::ReadyToMerge,
    ])->save();

    $this->repository = new LandingRepository;
    $this->implementations = new LandingImplementationRepository;
    $this->issues = new LandingIssues;
    $this->main = new LandingMain;
    $this->gateway = new LandingGateway;
    $transactionLevel = DB::transactionLevel();
    $this->repository->transactionLevel = $transactionLevel;
    $this->implementations->transactionLevel = $transactionLevel;
    $this->issues->transactionLevel = $transactionLevel;
    $this->main->transactionLevel = $transactionLevel;
    $this->gateway->transactionLevel = $transactionLevel;
    app()->instance(OrbitRepository::class, $this->repository);
    app()->instance(OrbitImplementationRepository::class, $this->implementations);
    app()->instance(OrbitActiveIssueProvider::class, $this->issues);
    app()->instance(OrbitMainCorrectnessInspector::class, $this->main);
    app()->instance(OrbitPullRequestLandingGateway::class, $this->gateway);
    Queue::fake();
});

afterEach(fn () => File::deleteDirectory($this->base));

function landingDispatch(PhaseRun $phase, string $role, string $name): AgentDispatch
{
    return AgentDispatch::query()->create([
        'phase_run_id' => $phase->id,
        'agent_role' => $role,
        'idempotency_key' => "landing-{$name}",
        'herdr_agent_name' => "orb-234-loop-{$name}",
        'prompt_name' => "orbit_{$name}",
        'prompt_version' => 1,
        'prompt_hash' => str_repeat('6', 64),
        'status' => AgentDispatchStatus::Settled,
        'settled_at' => now(),
    ]);
}

/** @param array<string, mixed> $payload */
function landingReceipt(PhaseRun $phase, string $kind, array $payload): Receipt
{
    return Receipt::query()->create([
        'phase_run_id' => $phase->id,
        'kind' => $kind,
        'schema_version' => 1,
        'payload' => $payload,
        'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
        'candidate_sha' => $payload['candidate_sha'],
        'validation_status' => ReceiptValidationStatus::Valid,
        'captured_at' => now(),
        'validated_at' => now(),
    ]);
}

/** @param array<string, mixed> $output */
function landingComplete(PhaseRun $phase, array $output): void
{
    $phase->forceFill([
        'status' => PhaseRunStatus::Completed,
        'output' => $output,
        'started_at' => now(),
        'finished_at' => now(),
    ])->save();
}

it('lands one exact approved Orbit candidate and replays as a no-op', function () {
    $action = app(AdvanceOrbitLanding::class);

    expect($action->handle($this->delivery->id, $this->landing->id))->toBeNull();

    $delivery = $this->delivery->fresh();
    $landing = $this->landing->fresh();

    expect($delivery->status)->toBe(DeliveryStatus::Landed)
        ->and($landing->status)->toBe(PhaseRunStatus::Completed)
        ->and($landing->current_block)->toBeNull()
        ->and($landing->output['main_sha'])->toBe(str_repeat('e', 40))
        ->and($landing->output['merge']['candidate_sha'])->toBe($this->candidateSha)
        ->and($landing->output['merge']['merge_commit_sha'])->toBe(str_repeat('f', 40))
        ->and($this->repository->reservationIsHeld())->toBeFalse()
        ->and($this->implementations->calls)->toBe(1)
        ->and($this->issues->calls)->toBe(1)
        ->and($this->main->calls)->toBe(2)
        ->and($this->gateway->reserveCalls)->toBe(1)
        ->and($this->gateway->inspectCalls)->toBe(1)
        ->and($this->gateway->mergeCalls)->toBe(1)
        ->and($this->gateway->releaseCalls)->toBe(1);

    expect($action->handle($this->delivery->id, $this->landing->id))->toBeNull()
        ->and($this->gateway->mergeCalls)->toBe(1)
        ->and($this->gateway->releaseCalls)->toBe(1);
});

it('waits without reserving a merge while Orbit main has correctness failures', function () {
    $this->main->failures = ['apps/gateway' => ['tool' => 'phpstan', 'exit_code' => 1]];

    expect(app(AdvanceOrbitLanding::class)->handle($this->delivery->id, $this->landing->id))
        ->toBe(AdvanceOrbitLanding::MAINTENANCE_RETRY_SECONDS)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::ReadyToMerge)
        ->and($this->delivery->fresh()->failure_details['code'])->toBe('landing_main_correctness_hold')
        ->and($this->gateway->reserveCalls)->toBe(0)
        ->and($this->gateway->mergeCalls)->toBe(0)
        ->and($this->repository->reservationIsHeld())->toBeFalse();
});

it('waits when another delivery owns the shared merge reservation', function () {
    $this->gateway->owned = false;

    expect(app(AdvanceOrbitLanding::class)->handle($this->delivery->id, $this->landing->id))
        ->toBe(AdvanceOrbitLanding::RETRY_SECONDS)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::ReadyToMerge)
        ->and($this->delivery->fresh()->failure_details['code'])->toBe('landing_merge_reservation_wait')
        ->and($this->gateway->inspectCalls)->toBe(0)
        ->and($this->gateway->releaseCalls)->toBe(0);
});

it('releases the reservation for pending or conflicting mergeability', function (?bool $mergeable, ?int $delay, DeliveryStatus $status) {
    $this->gateway->mergeable = $mergeable;

    expect(app(AdvanceOrbitLanding::class)->handle($this->delivery->id, $this->landing->id))
        ->toBe($delay)
        ->and($this->delivery->fresh()->status)->toBe($status)
        ->and($this->gateway->mergeCalls)->toBe(0)
        ->and($this->gateway->releaseCalls)->toBe(1);
})->with([
    'pending' => [null, AdvanceOrbitLanding::RETRY_SECONDS, DeliveryStatus::ReadyToMerge],
    'conflict' => [false, null, DeliveryStatus::Blocked],
]);

it('does not block a merge conflict until its reservation is released', function () {
    $this->gateway->mergeable = false;
    $this->gateway->releaseFailures = 1;
    $action = app(AdvanceOrbitLanding::class);

    expect(fn () => $action->handle($this->delivery->id, $this->landing->id))
        ->toThrow(OrbitPullRequestLandingFailed::class, 'release is unresolved');
    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::ReadyToMerge);

    expect($action->handle($this->delivery->id, $this->landing->id))->toBeNull()
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->gateway->releaseCalls)->toBe(2);
});

it('retains and resumes an unresolved merge without repeating pre-merge verification', function () {
    $this->gateway->mergeFailures = 1;
    $action = app(AdvanceOrbitLanding::class);

    expect(fn () => $action->handle($this->delivery->id, $this->landing->id))
        ->toThrow(OrbitPullRequestLandingFailed::class, 'merge outcome is unresolved');
    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Merging)
        ->and($this->landing->fresh()->current_block)->toBe('merge')
        ->and($this->gateway->releaseCalls)->toBe(0);

    expect($action->handle($this->delivery->id, $this->landing->id))->toBeNull()
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Landed)
        ->and($this->landing->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($this->implementations->calls)->toBe(1)
        ->and($this->main->calls)->toBe(2)
        ->and($this->gateway->reserveCalls)->toBe(2)
        ->and($this->gateway->mergeCalls)->toBe(2)
        ->and($this->gateway->releaseCalls)->toBe(1);
});

it('preserves a landed merge while reservation release is retried', function () {
    $this->gateway->releaseFailures = 1;
    $action = app(AdvanceOrbitLanding::class);

    expect(fn () => $action->handle($this->delivery->id, $this->landing->id))
        ->toThrow(OrbitPullRequestLandingFailed::class, 'release is unresolved');
    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Landed)
        ->and($this->landing->fresh()->current_block)->toBe('reservation_release')
        ->and($this->delivery->fresh()->failure_details['code'])
        ->toBe('landing_reservation_release_required');

    expect($action->handle($this->delivery->id, $this->landing->id))->toBeNull()
        ->and($this->landing->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($this->gateway->mergeCalls)->toBe(1)
        ->and($this->gateway->releaseCalls)->toBe(2);
});

it('rejects issue and configuration drift before merge', function (string $drift) {
    if ($drift === 'issue') {
        $this->issues->state = 'In Progress';
    } else {
        $this->repository->afterReserve = function (): void {
            $config = $this->delivery->projectOrchestration->config;
            $config['concurrency'] = 2;
            DB::table('project_orchestrations')
                ->where('id', $this->delivery->project_orchestration_id)
                ->update(['config' => json_encode($config, JSON_THROW_ON_ERROR)]);
        };
    }

    expect(fn () => app(AdvanceOrbitLanding::class)->handle($this->delivery->id, $this->landing->id))
        ->toThrow($drift === 'issue' ? OrbitIssueContractChanged::class : OrbitLandingAdvancementFailed::class);
    expect($this->gateway->mergeCalls)->toBe(0)
        ->and($this->repository->reservationIsHeld())->toBeFalse();
})->with(['issue', 'config']);

it('rejects retained receipt drift before any external landing work', function (string $receipt) {
    $source = $receipt === 'review' ? $this->reviewReceipt : $this->implementationReceipt;
    $payload = $source->payload;
    $payload['handoff'] = 'Tampered after approval.';
    DB::table('receipts')->where('id', $source->id)->update([
        'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
    ]);

    expect(fn () => app(AdvanceOrbitLanding::class)->handle($this->delivery->id, $this->landing->id))
        ->toThrow(OrbitLandingAdvancementFailed::class, 'landing intent is inconsistent');
    expect($this->repository->calls)->toBe(0)
        ->and($this->gateway->reserveCalls)->toBe(0)
        ->and($this->gateway->mergeCalls)->toBe(0);
})->with(['review', 'implementation']);

it('releases a merge reservation when approval evidence drifts', function () {
    $this->gateway->approvalMismatch = true;

    expect(fn () => app(AdvanceOrbitLanding::class)->handle($this->delivery->id, $this->landing->id))
        ->toThrow(OrbitLandingAdvancementFailed::class, 'no longer matches');
    expect($this->gateway->mergeCalls)->toBe(0)
        ->and($this->gateway->releaseCalls)->toBe(1);
});

it('queues the exact landing phase and honors action wait delays', function () {
    app(AdvanceDeliveryAction::class)->handle($this->delivery->id);
    Queue::assertPushed(
        AdvanceLandingJob::class,
        fn (AdvanceLandingJob $job): bool => $job->deliveryId === $this->delivery->id
            && $job->phaseRunId === $this->landing->id,
    );

    $this->main->failures = ['packages/php-sdk' => ['tool' => 'pest', 'exit_code' => 1]];
    $job = (new AdvanceLandingJob($this->delivery->id, $this->landing->id))
        ->withFakeQueueInteractions();
    $job->handle(app(AdvanceOrbitLanding::class));
    $job->assertReleased(AdvanceOrbitLanding::MAINTENANCE_RETRY_SECONDS);

    expect($job->tries)->toBe(0)
        ->and($job->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'))
        ->and(AdvanceLandingJob::LOCK_SECONDS)->toBeGreaterThan($job->timeout)
        ->and($job->retryUntil() > now())->toBeTrue();
});

it('queues incomplete landing recovery states', function (DeliveryStatus $status, string $block) {
    $this->delivery->forceFill(['status' => $status])->save();
    $this->landing->forceFill([
        'status' => PhaseRunStatus::Running,
        'current_block' => $block,
        'started_at' => now(),
    ])->save();

    app(AdvanceDeliveryAction::class)->handle($this->delivery->id);

    Queue::assertPushed(
        AdvanceLandingJob::class,
        fn (AdvanceLandingJob $job): bool => $job->deliveryId === $this->delivery->id
            && $job->phaseRunId === $this->landing->id,
    );
})->with([
    'merge read-back' => [DeliveryStatus::Merging, 'merge'],
    'reservation release' => [DeliveryStatus::Landed, 'reservation_release'],
]);

it('releases the landing job for the shared reservation wait', function () {
    $this->gateway->owned = false;
    $job = (new AdvanceLandingJob($this->delivery->id, $this->landing->id))
        ->withFakeQueueInteractions();

    $job->handle(app(AdvanceOrbitLanding::class));

    $job->assertReleased(AdvanceOrbitLanding::RETRY_SECONDS);
});

it('releases a contended phase-scoped landing lock for retry', function () {
    $lock = Cache::lock(
        "delivery:landing-advance:{$this->delivery->id}:{$this->landing->id}",
        AdvanceLandingJob::LOCK_SECONDS,
    );
    expect($lock->get())->toBeTrue();

    try {
        $job = (new AdvanceLandingJob($this->delivery->id, $this->landing->id))
            ->withFakeQueueInteractions();
        $job->handle(app(AdvanceOrbitLanding::class));
        $job->assertReleased(1);
    } finally {
        $lock->release();
    }
});

it('guards exhausted landing jobs and preserves recoverable external states', function (DeliveryStatus $status, PhaseRunStatus $phaseStatus, ?string $block, string $code) {
    $this->delivery->forceFill(['status' => $status])->save();
    $this->landing->forceFill([
        'status' => $phaseStatus,
        'current_block' => $block,
        'started_at' => $phaseStatus === PhaseRunStatus::Running ? now() : null,
    ])->save();

    (new AdvanceLandingJob($this->delivery->id, $this->landing->id))
        ->failed(new RuntimeException('Landing retries exhausted.'));

    $delivery = $this->delivery->fresh();
    expect($delivery->status)->toBe($status === DeliveryStatus::ReadyToMerge ? DeliveryStatus::Failed : $status)
        ->and($delivery->failure_details)->toBe([
            'code' => $code,
            'phase_run_id' => $this->landing->id,
            'message' => 'Landing retries exhausted.',
        ]);
})->with([
    'before merge' => [
        DeliveryStatus::ReadyToMerge,
        PhaseRunStatus::Pending,
        null,
        'landing_advancement_exhausted',
    ],
    'ambiguous merge' => [
        DeliveryStatus::Merging,
        PhaseRunStatus::Running,
        'merge',
        'landing_merge_reconciliation_required',
    ],
    'post-merge release' => [
        DeliveryStatus::Landed,
        PhaseRunStatus::Running,
        'reservation_release',
        'landing_reservation_release_required',
    ],
]);
