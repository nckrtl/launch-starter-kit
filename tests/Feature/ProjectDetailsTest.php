<?php

use App\Projects\GitHubProjects;
use App\Projects\OrbitProjects;
use App\Projects\ProjectDetails;
use App\Projects\SharedKnowledgeProjectRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    Process::preventStrayProcesses();
    config(['commander.orbit.url' => 'https://orbit.example', 'commander.orbit.ca' => '']);
});

it('exposes safe project details and defers remote integrations', function () {
    $path = storage_path('framework/testing/details-'.bin2hex(random_bytes(4)));
    config(['commander.projects_path' => $path]);
    app(SharedKnowledgeProjectRepository::class)->create('demo', ['name' => 'Demo', 'status' => 'active']);
    File::put($path.'/demo/project.yaml', Yaml::dump([
        'id' => 'demo', 'name' => 'Demo', 'secret' => 'not-for-the-browser',
        'applications' => [['name' => 'Demo app', 'repository' => 'git@github.com:acme/demo.git', 'development_url' => 'javascript:alert(1)']],
        'slack' => ['channels' => [['id' => 'C123', 'name' => 'demo']]],
    ], 8));
    try {
        $this->get('/projects/demo')->assertSuccessful()->assertInertia(fn ($page) => $page
            ->component('Projects/Show')->where('project.name', 'Demo')
            ->where('project.repositories', ['acme/demo'])->where('project.applications.0.url', null)
            ->where('project.channels.0.url', 'https://slack.com/app_redirect?channel=C123')
            ->missing('project.secret')->missing('orbit')->missing('github'));
        $this->get('/projects/missing')->assertNotFound();
        Http::assertNothingSent();
        Process::assertNothingRan();
    } finally {
        File::deleteDirectory($path);
    }
});

it('prefers explicit Orbit IDs and does not merge apps sharing a repository', function () {
    Http::fake([
        'orbit.example/api/v1/apps' => Http::response(['data' => [
            ['id' => 30, 'name' => 'Starter', 'slug' => 'starter', 'repository_url' => 'https://github.com/acme/starter.git'],
            ['id' => 34, 'name' => 'Commander', 'slug' => 'commander', 'repository_url' => 'https://github.com/acme/starter.git'],
        ]]),
        'orbit.example/api/v1/instances' => Http::response(['data' => [
            ['app_id' => 30, 'node_id' => 9, 'name' => 'main', 'url' => 'https://starter.test', 'status' => 'active'],
            ['app_id' => 34, 'node_id' => 9, 'name' => 'default', 'url' => 'https://commander.test'],
        ]]),
        'orbit.example/api/v1/nodes' => Http::response(['data' => [['id' => 9, 'name' => 'Beast']]]),
    ]);
    $service = app(OrbitProjects::class);
    $result = $service->get(['applications' => [['orbit_app_id' => 30, 'repository' => 'acme/starter']]]);
    expect($result['status'])->toBe('available')->and($result['apps'])->toHaveCount(1)
        ->and($result['apps'][0]['instances'])->toHaveCount(1)
        ->and($result['apps'][0]['instances'][0])->toMatchArray(['url' => 'https://starter.test', 'node' => 'Beast']);
    $ambiguous = $service->get(['id' => 'unknown', 'repositories' => ['acme/starter']]);
    expect($ambiguous['apps'])->toBe([])->and($ambiguous['unmatched'])->toHaveCount(1);
    $missing = $service->get(['applications' => [['orbit_app_id' => 999, 'orbit_app_slug' => 'starter']]]);
    expect($missing['apps'])->toBe([]);
    Http::assertSentCount(3);
});

it('reports Orbit outages instead of pretending there are no instances', function () {
    Http::fake(['*' => Http::response([], 503)]);
    expect(app(OrbitProjects::class)->get([])['status'])->toBe('unavailable');
});

it('keeps instances when node names are unavailable', function () {
    Http::fake([
        'orbit.example/api/v1/apps' => Http::response(['data' => [['id' => 1, 'name' => 'Demo', 'slug' => 'demo']]]),
        'orbit.example/api/v1/instances' => Http::response(['data' => [['app_id' => 1, 'node_id' => 9, 'name' => 'main', 'url' => 'https://demo.test']]]),
        'orbit.example/api/v1/nodes' => Http::response([], 503),
    ]);
    $result = app(OrbitProjects::class)->get(['id' => 'demo']);
    expect($result['status'])->toBe('available')
        ->and($result['apps'][0]['instances'][0])->toMatchArray(['node' => 'Node 9', 'url' => 'https://demo.test']);
});

it('includes runtime URLs from application manifests', function () {
    $details = app(ProjectDetails::class)->get(['applications' => [['name' => 'Home Assistant', 'runtime_url' => 'http://homeassistant.local:8123']]]);
    expect($details['applications'][0]['url'])->toBe('http://homeassistant.local:8123');
});

it('normalizes repository identities and excludes unsafe links', function () {
    expect(ProjectDetails::repository('https://github.com/acme/demo.git'))->toBe('acme/demo')
        ->and(ProjectDetails::repository('https://evil.test/acme/demo'))->toBeNull()
        ->and(ProjectDetails::repository('acme/demo" bad'))->toBeNull()
        ->and(ProjectDetails::url('javascript:alert(1)'))->toBeNull()
        ->and(ProjectDetails::url('https://user:secret@example.com'))->toBeNull();
});

it('fetches open PRs and issues in one cached read and keeps partial GitHub failures separate', function () {
    Process::fake(['*' => Process::result(output: json_encode(['data' => ['r0' => [
        'pullRequests' => ['totalCount' => 25, 'nodes' => [['number' => 7, 'title' => 'Ship it', 'isDraft' => true]]],
        'issues' => ['totalCount' => 1, 'nodes' => [['number' => 8, 'title' => 'Fix it']]],
    ], 'r1' => null]], JSON_THROW_ON_ERROR))]);
    $service = app(GitHubProjects::class);
    $result = $service->get(['acme/demo', 'acme/private']);
    expect($result[0]['status'])->toBe('available')->and($result[0]['pull_request_count'])->toBe(25)
        ->and($result[0]['pull_requests'][0])->toMatchArray(['url' => 'https://github.com/acme/demo/pull/7', 'draft' => true])
        ->and($result[0]['issues'][0]['url'])->toBe('https://github.com/acme/demo/issues/8')
        ->and($result[1]['status'])->toBe('unavailable');
    $service->get(['acme/demo', 'acme/private']);
    Process::assertRanTimes(fn () => true, 1);
});

it('keeps repository links when GitHub is unavailable and skips unconfigured repositories', function () {
    Process::fake(['*' => Process::result(errorOutput: 'authentication failed', exitCode: 1)]);
    $service = app(GitHubProjects::class);
    expect($service->get([]))->toBe([]);
    Process::assertNothingRan();
    expect($service->get(['acme/demo'])[0])->toMatchArray(['status' => 'unavailable', 'url' => 'https://github.com/acme/demo']);
});
