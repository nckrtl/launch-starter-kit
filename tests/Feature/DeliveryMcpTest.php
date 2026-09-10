<?php

use App\Delivery\Actions\ConfigureProjectOrchestration;
use App\Delivery\Actions\StartShadowDelivery;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Mcp\Servers\CommanderServer;
use App\Mcp\Tools\GetDelivery;
use App\Mcp\Tools\GetDeliveryTimeline;
use App\Models\AgentDispatch;
use App\Models\PhaseRun;
use App\Projects\SharedKnowledgeProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-10 12:00:00');
    $this->projectsPath = storage_path('framework/testing/delivery-mcp-projects-'.bin2hex(random_bytes(4)));
    File::makeDirectory($this->projectsPath, 0755, true);
    config()->set('commander.projects_path', $this->projectsPath);
    app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
    $project = app(ConfigureProjectOrchestration::class)->handle('orbit', [
        'type' => 'orbit',
        'repository' => '/home/nckrtl/orbit',
        'worktreeRoot' => '/fast/worktrees/orbit',
        'herdrSession' => 'orbit',
        'concurrency' => 1,
        'defaultFlow' => 'discovery',
    ]);
    $this->delivery = app(StartShadowDelivery::class)->handle(
        $project,
        'linear-234',
        'ORB-234',
        '/fast/worktrees/orbit/orb-234',
        str_repeat('a', 40),
    );
});

afterEach(function () {
    Carbon::setTestNow();
    File::deleteDirectory($this->projectsPath);
});

it('returns the authoritative delivery without private payloads or config', function () {
    CommanderServer::tool(GetDelivery::class, ['delivery_id' => $this->delivery->id])
        ->assertOk()
        ->assertStructuredContent([
            'delivery' => [
                'id' => $this->delivery->id,
                'project_id' => 'orbit',
                'issue_provider' => 'linear',
                'issue_id' => 'linear-234',
                'issue_key' => 'ORB-234',
                'workflow_type' => 'herdr-shadow',
                'workflow_version' => 1,
                'status' => 'queued',
                'current_phase' => 'herdr_test',
                'branch' => null,
                'worktree' => '/fast/worktrees/orbit/orb-234',
                'candidate_sha' => str_repeat('a', 40),
                'pull_request_number' => null,
                'pull_request_url' => null,
                'failure' => null,
                'completion' => null,
                'created_at' => '2026-09-10T12:00:00.000000Z',
                'updated_at' => '2026-09-10T12:00:00.000000Z',
                'completed_at' => null,
                'failed_at' => null,
            ],
            'current_phase_run' => null,
            'wait' => null,
        ]);
});

it('returns the current phase and Herdr wait reason', function () {
    $phase = PhaseRun::query()->create([
        'delivery_id' => $this->delivery->id,
        'phase_name' => 'herdr_test',
        'attempt' => 1,
        'status' => PhaseRunStatus::Waiting,
        'started_at' => now(),
    ]);
    $dispatch = AgentDispatch::query()->create([
        'phase_run_id' => $phase->id,
        'agent_role' => 'tester',
        'idempotency_key' => 'delivery-mcp-dispatch',
        'herdr_agent_name' => 'orb-234-test',
        'prompt_name' => 'harmless-test',
        'prompt_version' => 1,
        'prompt_hash' => str_repeat('b', 64),
        'status' => AgentDispatchStatus::Waiting,
        'dispatched_at' => now(),
    ]);

    CommanderServer::tool(GetDelivery::class, ['delivery_id' => $this->delivery->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('current_phase_run.id', $phase->id)
            ->where('current_phase_run.phase', 'herdr_test')
            ->where('current_phase_run.attempt', 1)
            ->where('current_phase_run.status', 'waiting')
            ->where('current_phase_run.dispatch_status', 'waiting')
            ->where('current_phase_run.agent_name', 'orb-234-test')
            ->where('wait', ['reason' => 'herdr_agent', 'details' => ['agent_name' => 'orb-234-test']])
            ->etc());

    expect($dispatch->exists)->toBeTrue();
});

it('returns empty and populated deterministic timelines', function () {
    CommanderServer::tool(GetDeliveryTimeline::class, ['delivery_id' => $this->delivery->id])
        ->assertOk()
        ->assertStructuredContent(['delivery_id' => $this->delivery->id, 'timeline' => []]);

    $phase = PhaseRun::query()->create([
        'delivery_id' => $this->delivery->id,
        'phase_name' => 'herdr_test',
        'attempt' => 1,
        'status' => PhaseRunStatus::Running,
        'started_at' => now(),
    ]);

    CommanderServer::tool(GetDeliveryTimeline::class, ['delivery_id' => $this->delivery->id])
        ->assertOk()
        ->assertStructuredContent([
            'delivery_id' => $this->delivery->id,
            'timeline' => [[
                'occurred_at' => '2026-09-10T12:00:00.000000+00:00',
                'type' => 'phase.started',
                'source' => 'phase_run',
                'source_id' => $phase->id,
                'details' => ['phase' => 'herdr_test', 'attempt' => 1],
            ]],
        ]);
});

it('returns concise errors for missing and invalid delivery IDs', function () {
    CommanderServer::tool(GetDelivery::class, ['delivery_id' => 999])
        ->assertHasErrors(['Delivery [999] was not found.']);
    CommanderServer::tool(GetDeliveryTimeline::class, ['delivery_id' => 999])
        ->assertHasErrors(['Delivery [999] was not found.']);
    CommanderServer::tool(GetDelivery::class, ['delivery_id' => 0])
        ->assertHasErrors(['delivery id']);
    CommanderServer::tool(GetDeliveryTimeline::class, ['delivery_id' => 'invalid'])
        ->assertHasErrors(['delivery id']);
});

it('registers read-only tools with explicit input and output schemas', function (string $toolClass) {
    $tool = app($toolClass)->toArray();

    expect($tool['annotations'])->toMatchArray(['readOnlyHint' => true])
        ->and($tool['inputSchema']['properties'])->toHaveKey('delivery_id')
        ->and($tool['inputSchema']['required'])->toContain('delivery_id')
        ->and($tool['outputSchema']['properties'])->not->toBeEmpty()
        ->and($tool['outputSchema']['required'])->not->toBeEmpty();

    CommanderServer::tool($toolClass, ['delivery_id' => $this->delivery->id])->assertOk();
})->with([GetDelivery::class, GetDeliveryTimeline::class]);
