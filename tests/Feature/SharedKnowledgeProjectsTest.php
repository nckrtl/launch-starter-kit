<?php

use App\Mcp\Servers\CommanderServer;
use App\Mcp\Tools\CreateProject;
use App\Mcp\Tools\ListProjects;
use App\Mcp\Tools\UpdateProject;
use App\Projects\SharedKnowledgeProjectRepository;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    $this->projectsPath = storage_path('framework/testing/projects-'.bin2hex(random_bytes(4)));
    File::makeDirectory($this->projectsPath, 0755, true);
    config()->set('commander.projects_path', $this->projectsPath);
});

afterEach(function () {
    File::deleteDirectory($this->projectsPath);
});

it('creates, lists, and updates project manifests without losing unknown fields', function () {
    $repository = app(SharedKnowledgeProjectRepository::class);
    $repository->create('orbit-control', ['name' => 'Orbit Control', 'status' => 'active']);

    $path = $this->projectsPath.'/orbit-control/project.yaml';
    $manifest = Yaml::parseFile($path);
    $manifest['orchestration'] = ['board' => 'orbit', 'worker_limit' => 1];
    File::put($path, Yaml::dump($manifest, 8, 2));

    $updated = $repository->update('orbit-control', ['name' => 'Orbit Commander', 'status' => 'paused']);

    expect($repository->all())->toHaveCount(1)
        ->and($updated['name'])->toBe('Orbit Commander')
        ->and($updated['orchestration']['board'])->toBe('orbit')
        ->and(Yaml::parseFile($path)['orchestration']['worker_limit'])->toBe(1);
});

it('rejects traversal and symlinked project directories', function () {
    $repository = app(SharedKnowledgeProjectRepository::class);

    expect(fn () => $repository->create('../escape', ['name' => 'Escape', 'status' => 'active']))
        ->toThrow(InvalidArgumentException::class);

    symlink(dirname($this->projectsPath), $this->projectsPath.'/linked');

    expect($repository->all())->toBe([]);
});

it('manages projects through Inertia actions', function () {
    $this->post('/projects', ['id' => 'commander', 'name' => 'Commander', 'status' => 'active'])
        ->assertRedirect('/projects');

    $this->get('/projects')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('Projects/Index')->has('projects', 1));

    $this->put('/projects/commander', ['name' => 'Commander App', 'status' => 'paused'])
        ->assertRedirect('/projects');

    expect(Yaml::parseFile($this->projectsPath.'/commander/project.yaml'))
        ->toMatchArray(['name' => 'Commander App', 'status' => 'paused']);
});

it('exposes the same project actions through Commander MCP tools', function () {
    CommanderServer::tool(CreateProject::class, ['id' => 'anna-project', 'name' => 'Anna Project'])
        ->assertOk()
        ->assertSee('anna-project');

    CommanderServer::tool(UpdateProject::class, ['id' => 'anna-project', 'status' => 'paused'])
        ->assertOk()
        ->assertSee('paused');

    CommanderServer::tool(ListProjects::class)
        ->assertOk()
        ->assertSee('Anna Project');
});

it('protects the web MCP transport with a bearer token', function () {
    config()->set('commander.mcp_token', 'test-secret');

    $this->post('/mcp/commander')->assertUnauthorized();
    $this->withToken('wrong')->post('/mcp/commander')->assertUnauthorized();
});

it('keeps legacy MCP initialization and project calls compatible', function () {
    config(['commander.mcp_token' => 'test-secret']);
    app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);

    $this->withToken('test-secret')->postJson('/mcp/commander', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1.0']],
    ])->assertSuccessful()->assertJsonPath('result.protocolVersion', '2025-11-25')
        ->assertHeaderMissing('MCP-Session-Id');

    $this->postJson('/mcp/commander', [
        'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
        'params' => ['name' => app(ListProjects::class)->name(), 'arguments' => []],
    ])->assertSuccessful()->assertJsonPath('result.structuredContent.projects.0.id', 'orbit');
});

it('discovers and calls the authenticated MCP server without a session', function () {
    config(['commander.mcp_token' => 'test-secret']);
    app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
    $meta = [
        'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
        'io.modelcontextprotocol/clientCapabilities' => [],
    ];
    $discovery = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover', 'params' => ['_meta' => $meta]];
    $headers = ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'server/discover'];

    $this->postJson('/mcp/commander', $discovery, $headers)->assertUnauthorized();
    $this->withToken('wrong')->postJson('/mcp/commander', $discovery, $headers)->assertUnauthorized();
    $this->withToken('test-secret')->postJson('/mcp/commander', $discovery, $headers)
        ->assertSuccessful()->assertJsonPath('result.supportedVersions', ['2026-07-28'])
        ->assertHeaderMissing('MCP-Session-Id');

    $name = app(ListProjects::class)->name();
    $this->postJson('/mcp/commander', [
        'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
        'params' => ['name' => $name, 'arguments' => [], '_meta' => $meta],
    ], ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => $name])
        ->assertSuccessful()->assertJsonPath('result.structuredContent.projects.0.id', 'orbit');
});

it('rejects missing or mismatched modern MCP headers', function (array $headers) {
    config(['commander.mcp_token' => 'test-secret']);

    $this->withToken('test-secret')->postJson('/mcp/commander', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => [
            'name' => 'list-projects', 'arguments' => [],
            '_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => [],
            ],
        ],
    ], $headers)->assertBadRequest()->assertJsonPath('error.code', -32020);
})->with([
    'missing headers' => [[]],
    'wrong protocol' => [['MCP-Protocol-Version' => '2025-11-25', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => 'list-projects']],
    'wrong method' => [['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/list', 'Mcp-Name' => 'list-projects']],
    'missing name' => [['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call']],
    'wrong name' => [['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => 'get-project']],
]);
