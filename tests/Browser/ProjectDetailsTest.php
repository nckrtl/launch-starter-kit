<?php

use App\Projects\SharedKnowledgeProjectRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

it('navigates to project details and shows Orbit instances Slack and GitHub activity', function () {
    Cache::flush();
    $path = storage_path('framework/testing/browser-details-'.bin2hex(random_bytes(4)));
    config(['commander.projects_path' => $path, 'commander.orbit.url' => 'https://orbit.example', 'commander.orbit.ca' => '']);
    app(SharedKnowledgeProjectRepository::class)->create('integration-demo', ['name' => 'Integration Demo', 'status' => 'active']);
    File::put($path.'/integration-demo/project.yaml', Yaml::dump([
        'id' => 'integration-demo', 'name' => 'Integration Demo',
        'applications' => [['name' => 'Demo', 'orbit_app_id' => 1, 'repository' => 'acme/demo']],
        'slack' => ['channels' => [['id' => 'C123', 'name' => 'demo']]],
    ], 8));
    Http::fake([
        'orbit.example/api/v1/apps' => Http::response(['data' => [['id' => 1, 'name' => 'Demo']]]),
        'orbit.example/api/v1/instances' => Http::response(['data' => [['app_id' => 1, 'node_id' => 9, 'name' => 'main', 'url' => 'https://demo.test', 'status' => 'active']]]),
        'orbit.example/api/v1/nodes' => Http::response(['data' => [['id' => 9, 'name' => 'Beast']]]),
    ]);
    Process::fake(['*' => Process::result(output: json_encode(['data' => ['r0' => [
        'pullRequests' => ['totalCount' => 1, 'nodes' => [['number' => 7, 'title' => 'Ship the integration', 'isDraft' => true]]],
        'issues' => ['totalCount' => 1, 'nodes' => [['number' => 8, 'title' => 'Track the rollout']]],
    ]]], JSON_THROW_ON_ERROR))]);
    try {
        visit('/projects')->click('Integration Demo')->assertPathIs('/projects/integration-demo')
            ->assertSee('Slack · #demo')->assertSee('https://demo.test')->assertSee('Beast')
            ->assertSeeIn('[data-slot="table-header"]', 'URL')->assertSeeIn('[data-slot="table-header"]', 'Node')
            ->assertDontSee('Environment')->assertDontSee('Branch')
            ->assertSeeIn('[data-slot="table"] td:last-child', 'Visit')
            ->assertAttribute('[data-slot="table"] td:first-child a', 'href', 'https://demo.test')
            ->assertAttribute('[data-slot="table"] td:first-child a', 'target', '_blank')
            ->assertAttribute('[data-slot="table"] td:last-child a', 'href', 'https://demo.test')
            ->assertAttribute('[data-slot="table"] td:last-child a', 'target', '_blank')
            ->assertAttribute('[data-slot="table"] td:last-child a', 'rel', 'noopener noreferrer')
            ->assertSee('Ship the integration')->assertSee('Track the rollout')->assertSee('Draft')
            ->assertNoJavaScriptErrors()->click('Back to projects')->assertPathIs('/projects');
    } finally {
        File::deleteDirectory($path);
    }
});
