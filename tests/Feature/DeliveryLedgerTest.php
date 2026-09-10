<?php

use App\Delivery\Actions\ConfigureProjectOrchestration;
use App\Delivery\Actions\StartShadowDelivery;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ReceiptValidationStatus;
use App\Delivery\Queries\DeliveryTimeline;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\ExternalEvent;
use App\Models\PhaseRun;
use App\Models\Receipt;
use App\Projects\SharedKnowledgeProjectRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->projectsPath = storage_path('framework/testing/ledger-projects-'.bin2hex(random_bytes(4)));
    File::makeDirectory($this->projectsPath, 0755, true);
    config()->set('commander.projects_path', $this->projectsPath);
    app(SharedKnowledgeProjectRepository::class)->create('orbit-ledger', ['name' => 'Orbit Ledger', 'status' => 'active']);
    $this->orchestration = app(ConfigureProjectOrchestration::class)->handle('orbit-ledger', ledgerOrbitConfig());
});

afterEach(fn () => File::deleteDirectory($this->projectsPath));

function ledgerDelivery(object $test, string $issueId = 'linear-1'): Delivery
{
    return app(StartShadowDelivery::class)->handle(
        $test->orchestration,
        $issueId,
        'ORB-1',
        '/fast/worktrees/orbit/orb-1',
        str_repeat('a', 40),
    );
}

function ledgerOrbitConfig(): array
{
    return [
        'type' => 'orbit', 'repository' => '/home/nckrtl/orbit',
        'worktreeRoot' => '/fast/worktrees/orbit', 'herdrSession' => 'orbit',
        'concurrency' => 3, 'defaultFlow' => 'discovery',
    ];
}

it('enforces one active delivery per project issue while retaining terminal history', function () {
    $first = ledgerDelivery($this);

    expect(fn () => ledgerDelivery($this))->toThrow(QueryException::class);

    $first->status = DeliveryStatus::Completed;
    $first->completed_at = now();
    $first->save();
    $second = ledgerDelivery($this);

    expect($first->fresh()->active_issue_key)->toBeNull()
        ->and($second->active_issue_key)->not->toBeNull()
        ->and(Delivery::active()->count())->toBe(1);
});

it('casts ledger state and enforces phase, dispatch, receipt, and provider-event uniqueness', function () {
    $delivery = ledgerDelivery($this);
    $phase = PhaseRun::create([
        'delivery_id' => $delivery->getKey(), 'phase_name' => 'herdr_test', 'attempt' => 1,
        'status' => PhaseRunStatus::Running, 'input' => ['safe' => true], 'started_at' => now(),
    ]);
    $dispatch = AgentDispatch::create([
        'phase_run_id' => $phase->getKey(), 'agent_role' => 'test', 'idempotency_key' => str_repeat('b', 64),
        'prompt_name' => 'test', 'prompt_version' => 1, 'prompt_hash' => str_repeat('c', 64),
        'status' => AgentDispatchStatus::Waiting,
    ]);
    $receipt = Receipt::create([
        'phase_run_id' => $phase->getKey(), 'kind' => 'herdr_test', 'schema_version' => 1,
        'payload' => ['ok' => true], 'payload_hash' => str_repeat('d', 64),
        'validation_status' => ReceiptValidationStatus::Valid, 'captured_at' => now(),
    ]);

    expect($phase->fresh()->status)->toBe(PhaseRunStatus::Running)
        ->and($phase->fresh()->input)->toBe(['safe' => true])
        ->and($dispatch->fresh()->status)->toBe(AgentDispatchStatus::Waiting)
        ->and(fn () => PhaseRun::create($phase->only(['delivery_id', 'phase_name', 'attempt'])))->toThrow(QueryException::class)
        ->and(fn () => $receipt->update(['payload' => ['changed' => true]]))->toThrow(LogicException::class)
        ->and(fn () => $receipt->delete())->toThrow(LogicException::class);

    ExternalEvent::create([
        'ingestion_id' => fake()->uuid(), 'provider' => 'github', 'provider_event_id' => 'evt-1',
        'event_kind' => 'test', 'payload' => [], 'payload_hash' => str_repeat('e', 64), 'received_at' => now(),
    ]);

    expect(fn () => ExternalEvent::create([
        'ingestion_id' => fake()->uuid(), 'provider' => 'github', 'provider_event_id' => 'evt-1',
        'event_kind' => 'test', 'payload' => [], 'payload_hash' => str_repeat('f', 64), 'received_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('builds a deterministic read-only delivery timeline', function () {
    $delivery = ledgerDelivery($this);
    $at = now()->startOfSecond();
    $phase = PhaseRun::create([
        'delivery_id' => $delivery->getKey(), 'phase_name' => 'herdr_test', 'attempt' => 1,
        'status' => PhaseRunStatus::Completed, 'started_at' => $at, 'finished_at' => $at,
    ]);
    AgentDispatch::create([
        'phase_run_id' => $phase->getKey(), 'agent_role' => 'test', 'idempotency_key' => str_repeat('1', 64),
        'prompt_name' => 'test', 'prompt_version' => 1, 'prompt_hash' => str_repeat('2', 64),
        'status' => AgentDispatchStatus::Settled, 'dispatched_at' => $at, 'settled_at' => $at,
    ]);

    $before = $delivery->fresh()->updated_at;
    $types = collect(app(DeliveryTimeline::class)->for($delivery))->pluck('type')->all();

    expect($types)->toBe(['phase.started', 'phase.finished', 'dispatch.sent', 'dispatch.settled'])
        ->and($delivery->fresh()->updated_at->equalTo($before))->toBeTrue();
});
