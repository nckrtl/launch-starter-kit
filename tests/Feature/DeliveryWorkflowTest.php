<?php

use App\Delivery\Actions\AdvanceDeliveryAction;
use App\Delivery\Actions\CaptureHarmlessReceipt;
use App\Delivery\Actions\CaptureHerdrEvent;
use App\Delivery\Actions\ConfigureProjectOrchestration;
use App\Delivery\Actions\StartShadowDelivery;
use App\Delivery\Contracts\HerdrRuntime;
use App\Delivery\Data\HerdrAgentIdentifiers;
use App\Delivery\Data\OpenedHerdrWorktree;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ReceiptValidationStatus;
use App\Jobs\AdvanceDelivery;
use App\Models\AgentDispatch;
use App\Models\ExternalEvent;
use App\Models\PhaseRun;
use App\Projects\SharedKnowledgeProjectRepository;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

final class WorkflowFakeHerdrRuntime implements HerdrRuntime
{
    /** @var list<string> */
    public array $calls = [];

    private int $sequence = 0;

    public bool $failStartOnce = false;

    public function openWorktree(string $path): OpenedHerdrWorktree
    {
        $this->calls[] = 'open:'.$path;
        $this->sequence++;

        return new OpenedHerdrWorktree("workspace-{$this->sequence}", "tab-{$this->sequence}", "pane-{$this->sequence}", "terminal-{$this->sequence}", false);
    }

    public function splitPane(string $paneId, string $workingDirectory): HerdrAgentIdentifiers
    {
        $this->calls[] = 'split:'.$paneId;

        return $this->ids($paneId, '');
    }

    public function startAgent(string $paneId, string $name): HerdrAgentIdentifiers
    {
        $this->calls[] = 'start:'.$name;

        if ($this->failStartOnce) {
            $this->failStartOnce = false;

            throw new RuntimeException('ambiguous start');
        }

        return $this->ids($paneId, $name);
    }

    public function promptAgent(string $name, string $prompt): HerdrAgentIdentifiers
    {
        $this->calls[] = 'prompt:'.$name;

        return $this->ids("pane-{$this->sequence}", $name);
    }

    public function getAgent(string $name): HerdrAgentIdentifiers
    {
        $this->calls[] = 'get:'.$name;

        return $this->ids("pane-{$this->sequence}", $name);
    }

    private function ids(string $paneId, string $name): HerdrAgentIdentifiers
    {
        return new HerdrAgentIdentifiers("workspace-{$this->sequence}", "tab-{$this->sequence}", $paneId, "terminal-{$this->sequence}", 'codex', $name, $this->sequence);
    }
}

beforeEach(function () {
    $this->projectsPath = storage_path('framework/testing/workflow-projects-'.bin2hex(random_bytes(4)));
    File::makeDirectory($this->projectsPath, 0755, true);
    config()->set('commander.projects_path', $this->projectsPath);
    config()->set('herdr.session', 'orbit');
    app(SharedKnowledgeProjectRepository::class)->create('orbit-workflow', ['name' => 'Orbit Workflow', 'status' => 'active']);
    $orchestration = app(ConfigureProjectOrchestration::class)->handle('orbit-workflow', workflowOrbitConfig());
    $this->delivery = app(StartShadowDelivery::class)->handle(
        $orchestration,
        'linear-workflow-1',
        'ORB-77',
        '/fast/worktrees/orbit/orb-77',
        str_repeat('a', 40),
    );
    $this->herdr = new WorkflowFakeHerdrRuntime;
    app()->instance(HerdrRuntime::class, $this->herdr);
});

afterEach(fn () => File::deleteDirectory($this->projectsPath));

function workflowOrbitConfig(array $overrides = []): array
{
    return [
        'type' => 'orbit', 'repository' => '/home/nckrtl/orbit',
        'worktreeRoot' => '/fast/worktrees/orbit', 'herdrSession' => 'orbit',
        'concurrency' => 3, 'defaultFlow' => 'discovery',
        ...$overrides,
    ];
}

function workflowReceipt(PhaseRun $phase): array
{
    $phase->loadMissing(['delivery', 'agentDispatches']);

    return [
        'kind' => 'herdr_test',
        'schema_version' => 1,
        'delivery_id' => $phase->delivery_id,
        'dispatch_id' => $phase->agentDispatches->first()->getKey(),
        'issue_key' => $phase->delivery->external_issue_key,
        'phase' => $phase->phase_name,
        'attempt' => $phase->attempt,
        'outcome' => 'success',
        'worktree' => $phase->delivery->worktree_path,
        'head_sha' => $phase->delivery->candidate_sha,
        'commands' => [['command' => 'git rev-parse HEAD', 'exit_code' => 0]],
        'artifacts' => [],
        'summary' => 'Harmless receipt complete.',
        'created_at' => now()->toISOString(),
    ];
}

it('starts each phase once and advances two phases idempotently from repeated events and jobs', function () {
    Queue::fake();
    $action = app(AdvanceDeliveryAction::class);
    $job = new AdvanceDelivery($this->delivery->getKey());

    $job->handle($action);
    $job->handle($action);

    expect($this->herdr->calls)->toHaveCount(3)
        ->and(AgentDispatch::count())->toBe(1)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::WaitingForAgent);

    config()->set('herdr.orchestration.enabled', true);
    $dispatch = AgentDispatch::sole();
    $envelope = [
        'event' => 'pane.agent_status_changed',
        'data' => [
            'pane_id' => $dispatch->herdr_pane_id,
            'workspace_id' => $dispatch->herdr_workspace_id,
            'agent_status' => 'idle',
        ],
    ];
    app(CaptureHerdrEvent::class)->handle($envelope);
    app(CaptureHerdrEvent::class)->handle($envelope);

    expect(ExternalEvent::count())->toBe(2)
        ->and($dispatch->fresh()->status)->toBe(AgentDispatchStatus::Settled);

    $phase = PhaseRun::sole();
    $firstReceipt = app(CaptureHarmlessReceipt::class)->handle($phase, workflowReceipt($phase));
    expect($firstReceipt->validation_errors)->toBeNull()
        ->and($firstReceipt->validation_status)->toBe(ReceiptValidationStatus::Valid);
    $job->handle($action);
    $job->handle($action);
    $job->handle($action);

    expect(PhaseRun::where('phase_name', 'herdr_test')->sole()->status)->toBe(PhaseRunStatus::Completed)
        ->and(AgentDispatch::count())->toBe(2)
        ->and(collect($this->herdr->calls)->filter(fn (string $call) => str_starts_with($call, 'start:')))->toHaveCount(2);

    $second = PhaseRun::where('phase_name', 'herdr_confirm')->sole();
    $secondDispatch = $second->agentDispatches()->sole();
    app(CaptureHerdrEvent::class)->handle([
        'event' => 'pane.agent_status_changed',
        'data' => [
            'pane_id' => $secondDispatch->herdr_pane_id,
            'workspace_id' => $secondDispatch->herdr_workspace_id,
            'agent_status' => 'done',
        ],
    ]);
    app(CaptureHarmlessReceipt::class)->handle($second, workflowReceipt($second));
    $job->handle($action);
    $job->handle($action);

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Completed)
        ->and($this->delivery->fresh()->active_issue_key)->toBeNull()
        ->and(PhaseRun::where('status', PhaseRunStatus::Completed)->count())->toBe(2)
        ->and(AgentDispatch::count())->toBe(2);
});

it('uses the latest project config when an active delivery advances', function () {
    Queue::fake();
    app(ConfigureProjectOrchestration::class)->handle(
        'orbit-workflow',
        workflowOrbitConfig(['herdrSession' => 'orbit-updated']),
    );

    (new AdvanceDelivery($this->delivery->id))->handle(app(AdvanceDeliveryAction::class));

    expect(AgentDispatch::sole()->herdr_session)->toBe('orbit-updated');
});

it('rejects corrupt live config before calling Herdr', function () {
    DB::table('project_orchestrations')
        ->where('id', $this->delivery->project_orchestration_id)
        ->update(['config' => json_encode(['type' => 'orbit'], JSON_THROW_ON_ERROR)]);

    expect(fn () => (new AdvanceDelivery($this->delivery->id))->handle(app(AdvanceDeliveryAction::class)))
        ->toThrow(ValidationException::class)
        ->and($this->herdr->calls)->toBe([]);
});

it('rejects an unsupported workflow version before calling Herdr', function () {
    $this->delivery->workflow_version = 999;
    $this->delivery->save();

    expect(fn () => (new AdvanceDelivery($this->delivery->id))->handle(app(AdvanceDeliveryAction::class)))
        ->toThrow(InvalidArgumentException::class)
        ->and($this->herdr->calls)->toBe([]);
});

it('captures invalid receipts once and blocks without advancing', function () {
    Queue::fake();
    $job = new AdvanceDelivery($this->delivery->getKey());
    $job->handle(app(AdvanceDeliveryAction::class));
    $phase = PhaseRun::sole();
    $payload = workflowReceipt($phase);
    $payload['head_sha'] = str_repeat('b', 40);
    $payload['commands'][0]['exit_code'] = 1;

    $receipt = app(CaptureHarmlessReceipt::class)->handle($phase, $payload);

    expect($receipt->validation_status)->toBe(ReceiptValidationStatus::Invalid)
        ->and($receipt->validation_errors)->toContain('receipt_sha_mismatch', 'receipt_check_failed')
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and(Queue::pushed(AdvanceDelivery::class))->toBeEmpty();
});

it('marks a missing receipt as actionable and recovers when the receipt arrives late', function () {
    Queue::fake();
    $job = new AdvanceDelivery($this->delivery->id);
    $action = app(AdvanceDeliveryAction::class);
    $job->handle($action);
    $dispatch = AgentDispatch::sole();
    $dispatch->status = AgentDispatchStatus::Settled;
    $dispatch->settled_at = now();
    $dispatch->save();

    $job->handle($action);

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->delivery->fresh()->failure_details['code'])->toBe('receipt_missing');

    $phase = PhaseRun::sole();
    app(CaptureHarmlessReceipt::class)->handle($phase, workflowReceipt($phase));
    $job->handle($action);

    expect($phase->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Queued);
});

it('reconciles an ambiguous deterministic agent start without starting a second agent', function () {
    Queue::fake();
    $this->herdr->failStartOnce = true;
    $job = new AdvanceDelivery($this->delivery->id);
    $action = app(AdvanceDeliveryAction::class);

    expect(fn () => $job->handle($action))->toThrow(RuntimeException::class, 'ambiguous start');
    $job->handle($action);

    expect(collect($this->herdr->calls)->filter(fn (string $call) => str_starts_with($call, 'start:')))->toHaveCount(1)
        ->and(collect($this->herdr->calls)->filter(fn (string $call) => str_starts_with($call, 'get:')))->toHaveCount(1)
        ->and(AgentDispatch::sole()->status)->toBe(AgentDispatchStatus::Waiting);
});

it('releases a contended delivery lock for retry', function () {
    $lock = Cache::lock("delivery:advance:{$this->delivery->id}", 60);
    $lock->get();

    try {
        $job = (new AdvanceDelivery($this->delivery->id))->withFakeQueueInteractions();
        $job->handle(app(AdvanceDeliveryAction::class));
        $job->assertReleased(1);
    } finally {
        $lock->release();
    }
});

it('uses an after-commit job with a timeout below the database retry window', function () {
    $job = new AdvanceDelivery($this->delivery->getKey());

    expect($job)->toBeInstanceOf(ShouldQueueAfterCommit::class)
        ->and($job->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'));
});
