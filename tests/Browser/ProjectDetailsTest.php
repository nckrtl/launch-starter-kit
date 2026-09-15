<?php

use App\Projects\SharedKnowledgeProjectRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

it('navigates to project details and filters GitHub pull requests and issues', function () {
    Cache::flush();
    $path = storage_path('framework/testing/browser-details-'.bin2hex(random_bytes(4)));
    config(['commander.projects_path' => $path, 'commander.orbit.url' => 'https://orbit.example', 'commander.orbit.ca' => '']);
    app(SharedKnowledgeProjectRepository::class)->create('integration-demo', ['name' => 'Integration Demo', 'status' => 'active']);
    File::put($path.'/integration-demo/project.yaml', Yaml::dump([
        'id' => 'integration-demo', 'name' => 'Integration Demo',
        'applications' => [['id' => 'demo', 'name' => 'Demo', 'repository' => 'acme/demo', 'development_url' => 'https://demo.test']],
        'slack' => ['channels' => [['id' => 'C123', 'name' => 'demo']]],
        'locations' => [['machine' => 'beast', 'path' => '/fast/apps/demo']],
    ], 8));
    Http::fake([
        'orbit.example/api/v1/apps' => Http::response(['data' => [['id' => 1, 'name' => 'Demo', 'slug' => 'demo']]]),
        'orbit.example/api/v1/instances' => Http::response(['data' => [
            ['app_id' => 1, 'node_id' => 9, 'name' => 'tasks', 'url' => 'https://tasks.demo.test', 'status' => 'active'],
            ['app_id' => 1, 'node_id' => 9, 'name' => 'default', 'url' => 'https://demo.test', 'status' => 'active'],
        ]]),
        'orbit.example/api/v1/nodes' => Http::response(['data' => [['id' => 9, 'name' => 'Beast']]]),
    ]);
    Process::fake(['*' => Process::result(output: json_encode(['data' => ['r0' => [
        'pullRequestsAll' => ['totalCount' => 2, 'nodes' => [
            ['number' => 7, 'title' => 'Ship the integration', 'isDraft' => true, 'state' => 'OPEN'],
            ['number' => 9, 'title' => 'Merge the integration', 'isDraft' => false, 'state' => 'MERGED'],
        ]],
        'pullRequestsOpen' => ['totalCount' => 1, 'nodes' => [['number' => 7, 'title' => 'Ship the integration', 'isDraft' => true, 'state' => 'OPEN']]],
        'pullRequestsClosed' => ['totalCount' => 1, 'nodes' => [['number' => 9, 'title' => 'Merge the integration', 'isDraft' => false, 'state' => 'MERGED']]],
        'issuesAll' => ['totalCount' => 2, 'nodes' => [
            ['number' => 8, 'title' => 'Track the rollout', 'state' => 'OPEN'],
            ['number' => 10, 'title' => 'Close the rollout', 'state' => 'CLOSED'],
        ]],
        'issuesOpen' => ['totalCount' => 1, 'nodes' => [['number' => 8, 'title' => 'Track the rollout', 'state' => 'OPEN']]],
        'issuesClosed' => ['totalCount' => 1, 'nodes' => [['number' => 10, 'title' => 'Close the rollout', 'state' => 'CLOSED']]],
    ]]], JSON_THROW_ON_ERROR))]);
    try {
        visit('/projects')->click('Integration Demo')->assertPathIs('/projects/integration-demo')
            ->assertDontSee('Project links')->assertDontSee('#demo')->assertDontSee('/fast/apps/demo')
            ->assertCount('[aria-label="Project links"] a', 3)
            ->assertAttribute('[aria-label="Open acme/demo on GitHub"]', 'href', 'https://github.com/acme/demo')
            ->assertAttribute('[aria-label="Open acme/demo on GitHub"]', 'target', '_blank')
            ->hover('[aria-label="Open acme/demo on GitHub"]')
            ->assertSeeIn('[data-slot="tooltip-content"]', 'Open acme/demo on GitHub')
            ->assertAttribute('[aria-label="Open #demo in the Slack desktop app"]', 'href', 'slack://channel?id=C123')
            ->assertAttributeMissing('[aria-label="Open #demo in the Slack desktop app"]', 'target')
            ->assertAttribute('[aria-label="Open Demo website"]', 'href', 'https://demo.test')
            ->assertAttribute('[aria-label="Open Demo website"]', 'target', '_blank')
            ->assertCount('[aria-label="Project sections"] [role="tab"]', 4)
            ->assertAttribute('[aria-label="Project sections"] [role="tab"]:first-child', 'aria-selected', 'true')
            ->assertAttribute('[aria-label="Project sections"] [role="tab"]:last-child', 'aria-selected', 'false')
            ->assertAttribute('[aria-label="Project sections"] [role="tab"]:nth-child(2)', 'href', '/projects/integration-demo?section=instances')
            ->assertAttribute('[aria-label="Project sections"] [role="tab"]:nth-child(3)', 'href', '/projects/integration-demo?section=github')
            ->assertAttribute('[aria-label="Project sections"] [role="tab"]:last-child', 'href', '/projects/integration-demo/tasks')
            ->assertMissing('[data-slot="card"]')
            ->click('Instances')->assertQueryStringHas('section', 'instances')
            ->assertAttribute('[aria-label="Project sections"] [role="tab"]:nth-child(2)', 'aria-selected', 'true')
            ->assertSee('https://tasks.demo.test')->assertSee('https://demo.test')->assertSee('Beast')
            ->assertDontSee('Registered instances visible to this machine. Cached for 30 seconds.')
            ->assertMissing('section h2')
            ->assertSeeIn('table[aria-label="Demo instances"] thead', 'URL')->assertSeeIn('table[aria-label="Demo instances"] thead', 'Node')
            ->assertDontSee('Environment')->assertDontSee('Branch')
            ->assertCount('table[aria-label="Demo instances"] tbody tr', 2)
            ->assertAttribute('table[aria-label="Demo instances"] tbody tr:first-child td:first-child a', 'href', 'https://tasks.demo.test')
            ->assertAttribute('table[aria-label="Demo instances"] tbody tr:nth-child(2) td:first-child a', 'href', 'https://demo.test')
            ->assertAttribute('table[aria-label="Demo instances"] tbody tr:first-child td:last-child a', 'href', 'https://tasks.demo.test')
            ->assertAttribute('table[aria-label="Demo instances"] tbody tr:nth-child(2) td:last-child a', 'href', 'https://demo.test')
            ->assertAttribute('table[aria-label="Demo instances"] tbody tr:first-child td:last-child a', 'target', '_blank')
            ->assertAttribute('table[aria-label="Demo instances"] tbody tr:first-child td:last-child a', 'rel', 'noopener noreferrer')
            ->assertDontSee('Pull requests')
            ->click('GitHub')->assertQueryStringHas('section', 'github')
            ->assertAttribute('[aria-label="Project sections"] [role="tab"]:nth-child(3)', 'aria-selected', 'true')
            ->assertDontSee('Orbit instances')
            ->assertCount('a[href="https://github.com/acme/demo"]', 1)
            ->assertDontSee('Pull requests and issues, most recently updated first.')
            ->assertSeeIn('[data-slot="card-header"] [role="tablist"]', 'Pull requests')
            ->assertSeeIn('[data-slot="card-header"] [role="tablist"]', 'Issues')
            ->assertSee('GitHub')->assertSee('Pull requests')->assertSee('Ship the integration')
            ->assertDontSee('Merge the integration')->assertSee('Draft')->assertDontSee('Track the rollout')
            ->assertMissing('[aria-label="Show all pull requests"]')
            ->assertSeeIn('table[aria-label="Open pull requests"] thead', 'Pull request')
            ->assertSeeIn('table[aria-label="Open pull requests"] thead', 'Status')
            ->assertCount('table[aria-label="Open pull requests"] tbody tr', 1)
            ->assertAttribute('[aria-label="Open #7 Ship the integration on GitHub"]', 'href', 'https://github.com/acme/demo/pull/7')
            ->assertAttribute('[aria-label="Open #7 Ship the integration on GitHub"]', 'target', '_blank')
            ->assertDontSee('View on GitHub')
            ->click('[aria-label="Show closed pull requests"]')->assertSee('Merge the integration')->assertDontSee('Ship the integration')
            ->click('[aria-label="Show issues"]')->assertSee('Track the rollout')->assertDontSee('Close the rollout')
            ->assertDontSee('Ship the integration')
            ->assertMissing('[aria-label="Show all issues"]')
            ->click('[aria-label="Show closed issues"]')->assertSee('Close the rollout')->assertDontSee('Track the rollout')
            ->assertDontSee('Checked')
            ->assertNoJavaScriptErrors()->click('Back to projects')->assertPathIs('/projects');
    } finally {
        File::deleteDirectory($path);
    }
});
