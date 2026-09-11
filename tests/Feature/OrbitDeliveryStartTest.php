<?php

use App\Delivery\Actions\AdvanceDeliveryAction;
use App\Delivery\Actions\ConfigureProjectOrchestration;
use App\Delivery\Actions\StartOrbitDelivery;
use App\Delivery\Contracts\HerdrRuntime;
use App\Delivery\Data\CandidateCheck;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Projects\SharedKnowledgeProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->projectsPath = storage_path('framework/testing/orbit-delivery-start-'.bin2hex(random_bytes(4)));
    File::makeDirectory($this->projectsPath, 0755, true);
    config()->set('commander.projects_path', $this->projectsPath);
    app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
    $this->project = app(ConfigureProjectOrchestration::class)->handle('orbit', [
        'type' => 'orbit',
        'repository' => '/home/nckrtl/orbit',
        'worktreeRoot' => '/fast/worktrees/orbit',
        'herdrSession' => 'orbit',
        'concurrency' => 1,
        'defaultFlow' => 'discovery',
    ]);
    $this->worktree = '/fast/worktrees/orbit/orb-234';
    $this->issue = verifiedOrbitIssueSnapshot(
        '11111111-2222-4333-8444-555555555555',
        'ORB-234',
        $this->worktree.'/.loop/issue.json',
    );
    $this->candidate = new CandidateCheck(
        '/home/nckrtl/orbit/.git/orbit-checks/'.str_repeat('a', 40).'/startup/result.json',
        str_repeat('a', 40),
        str_repeat('b', 40),
    );
    Queue::fake();
    mock(HerdrRuntime::class)
        ->shouldNotReceive('openWorktree', 'splitPane', 'startAgent', 'promptAgent', 'getAgent');
});

afterEach(fn () => File::deleteDirectory($this->projectsPath));

it('records a live Orbit delivery and exact planning preparation without dispatching it', function () {
    $delivery = app(StartOrbitDelivery::class)->handle(
        $this->project,
        $this->issue,
        $this->worktree,
        $this->candidate,
    );
    $phase = PhaseRun::sole();

    expect($delivery->workflow_type)->toBe(OrbitFeatureWorkflow::TYPE)
        ->and($delivery->workflow_version)->toBe(OrbitFeatureWorkflow::VERSION)
        ->and($delivery->status)->toBe(DeliveryStatus::Preparing)
        ->and($delivery->current_phase)->toBe(OrbitFeatureWorkflow::INITIAL_PHASE)
        ->and($delivery->external_issue_provider)->toBe('linear')
        ->and($delivery->external_issue_id)->toBe($this->issue->snapshot->issueId)
        ->and($delivery->external_issue_key)->toBe('ORB-234')
        ->and($delivery->branch)->toBe('orb-234')
        ->and($delivery->worktree_path)->toBe($this->worktree)
        ->and($delivery->candidate_sha)->toBe($this->candidate->candidateSha)
        ->and($phase->delivery_id)->toBe($delivery->id)
        ->and($phase->phase_name)->toBe('planning')
        ->and($phase->attempt)->toBe(1)
        ->and($phase->status)->toBe(PhaseRunStatus::Pending)
        ->and($phase->input)->toBe([
            'flow' => 'discovery',
            'issue_snapshot' => [
                'schema' => $this->issue->snapshot->schema,
                'provider' => $this->issue->snapshot->provider,
                'issue_id' => $this->issue->snapshot->issueId,
                'issue_key' => $this->issue->snapshot->issueKey,
                'path' => $this->issue->snapshot->path,
                'contents_sha256' => $this->issue->snapshot->contentsHash,
                'contract_schema' => $this->issue->snapshot->contractSchema,
                'contract_sha256' => $this->issue->snapshot->contractHash,
                'verified_at' => $this->issue->verifiedAt->toISOString(),
            ],
            'candidate_check' => [
                'receipt_path' => $this->candidate->receiptPath,
                'candidate_sha' => $this->candidate->candidateSha,
                'tree_sha' => $this->candidate->treeSha,
            ],
        ])
        ->and(AgentDispatch::count())->toBe(0);

    expect(app(AdvanceDeliveryAction::class)->handle($delivery->id))->toBeFalse();
    Queue::assertNothingPushed();
});

it('rejects inconsistent live Orbit preparation before writing the ledger', function (string $case) {
    $issue = $case === 'snapshot path'
        ? verifiedOrbitIssueSnapshot(
            '11111111-2222-4333-8444-555555555555',
            'ORB-234',
            '/fast/worktrees/orbit/different/.loop/issue.json',
        )
        : $this->issue;
    $candidate = $case === 'tree'
        ? new CandidateCheck($this->candidate->receiptPath, $this->candidate->candidateSha, 'not-a-tree')
        : ($case === 'receipt repository'
            ? new CandidateCheck('/different/.git/orbit-checks/'.str_repeat('a', 40).'/startup/result.json', $this->candidate->candidateSha, $this->candidate->treeSha)
            : $this->candidate);

    expect(fn () => app(StartOrbitDelivery::class)->handle(
        $this->project,
        $issue,
        $this->worktree,
        $candidate,
    ))->toThrow(InvalidArgumentException::class, 'prepared Orbit delivery inputs are inconsistent');

    expect(Delivery::count())->toBe(0)
        ->and(PhaseRun::count())->toBe(0)
        ->and(AgentDispatch::count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['snapshot path', 'tree', 'receipt repository']);

it('rejects an ineligible project or worktree binding before writing the live ledger', function (string $case) {
    if ($case === 'paused') {
        $this->project->update(['state' => ProjectOrchestrationState::Paused]);
    } elseif ($case === 'config type') {
        $this->project->forceFill(['config' => ['type' => 'different']])->save();
    } elseif ($case === 'proof flow') {
        $config = $this->project->config;
        $config['defaultFlow'] = 'proof';
        $this->project->forceFill(['config' => $config])->save();
    } else {
        $config = $this->project->config;
        $config['worktreeRoot'] = '/fast/worktrees/different';
        $this->project->forceFill(['config' => $config])->save();
    }

    expect(fn () => app(StartOrbitDelivery::class)->handle(
        $this->project,
        $this->issue,
        $this->worktree,
        $this->candidate,
    ))->toThrow(InvalidArgumentException::class);

    expect(Delivery::count())->toBe(0)
        ->and(PhaseRun::count())->toBe(0)
        ->and(AgentDispatch::count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['paused', 'config type', 'proof flow', 'worktree root']);
