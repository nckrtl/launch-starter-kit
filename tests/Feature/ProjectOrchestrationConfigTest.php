<?php

use App\Delivery\Actions\ConfigureProjectOrchestration;
use App\Delivery\Config\ProjectConfigRegistry;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Mcp\Servers\CommanderServer;
use App\Mcp\Tools\GetProjectConfig;
use App\Mcp\Tools\GetProjectStatus;
use App\Models\ProjectOrchestration;
use App\Projects\SharedKnowledgeProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->projectsPath = storage_path('framework/testing/orchestration-projects-'.bin2hex(random_bytes(4)));
    File::makeDirectory($this->projectsPath, 0755, true);
    config()->set('commander.projects_path', $this->projectsPath);
    app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
});

afterEach(fn () => File::deleteDirectory($this->projectsPath));

function orbitConfig(array $overrides = []): array
{
    return [
        'type' => 'orbit',
        'repository' => '/home/nckrtl/orbit',
        'worktreeRoot' => '/fast/worktrees/orbit',
        'herdrSession' => 'orbit',
        'concurrency' => 3,
        'defaultFlow' => 'discovery',
        ...$overrides,
    ];
}

it('stores validated config as plain JSON with an enum state', function () {
    $project = app(ConfigureProjectOrchestration::class)->handle('orbit', orbitConfig());
    $fresh = $project->fresh();

    expect($fresh->config)->toEqual(orbitConfig())
        ->and($fresh->config['repository'])->toBe('/home/nckrtl/orbit')
        ->and($fresh->state)->toBe(ProjectOrchestrationState::Enabled)
        ->and(json_decode($fresh->getRawOriginal('config'), true))->toEqual(orbitConfig());
});

it('hydrates valid config and rejects unknown fields and types', function () {
    expect(app(ProjectConfigRegistry::class)->hydrate(orbitConfig()))->toBeInstanceOf(OrbitProjectConfig::class)
        ->and(fn () => app(ProjectConfigRegistry::class)->hydrate(orbitConfig(['extra' => true])))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(ProjectConfigRegistry::class)->hydrate(orbitConfig(['type' => 'unknown'])))
        ->toThrow(InvalidArgumentException::class);
});

it('validates fields and upserts one orchestration for an existing manifest', function () {
    $action = app(ConfigureProjectOrchestration::class);
    $first = $action->handle('orbit', orbitConfig());
    $first->state = ProjectOrchestrationState::Paused;
    $first->save();
    $second = $action->handle('orbit', orbitConfig(['concurrency' => 4]));

    expect($second->is($first))->toBeTrue()
        ->and(ProjectOrchestration::count())->toBe(1)
        ->and($second->config['concurrency'])->toBe(4)
        ->and($second->state)->toBe(ProjectOrchestrationState::Paused)
        ->and(fn () => $action->handle('missing', orbitConfig()))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $action->handle('orbit', orbitConfig(['repository' => 'relative'])))
        ->toThrow(ValidationException::class);
});

it('exposes configured and unconfigured status through read-only MCP tools', function () {
    CommanderServer::tool(GetProjectConfig::class, ['id' => 'orbit'])
        ->assertOk()
        ->assertStructuredContent([
            'project_id' => 'orbit',
            'configured' => false,
            'state' => null,
            'config' => null,
        ]);

    app(ConfigureProjectOrchestration::class)->handle('orbit', orbitConfig());

    CommanderServer::tool(GetProjectStatus::class, ['id' => 'orbit'])
        ->assertOk()
        ->assertStructuredContent([
            'project' => ['id' => 'orbit', 'name' => 'Orbit', 'manifest_status' => 'active'],
            'orchestration' => [
                'configured' => true,
                'state' => 'enabled',
                'config_type' => 'orbit',
            ],
        ]);
});
