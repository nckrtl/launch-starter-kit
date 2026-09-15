<?php

use App\Projects\SharedKnowledgeProjectRepository;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->projectsPath = storage_path('framework/testing/browser-projects-'.bin2hex(random_bytes(4)));
    File::makeDirectory($this->projectsPath, 0755, true);
    config(['commander.projects_path' => $this->projectsPath]);
    app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
});

afterEach(fn () => File::deleteDirectory($this->projectsPath));

it('shows the shared project management interface without javascript errors', function () {
    $page = visit('/projects');

    $page->assertSee('Projects')
        ->assertDontSee('Create project')
        ->assertSee('Orbit')
        ->click('Create')
        ->assertSee('Create project')
        ->assertSee('Project ID')
        ->click('Cancel')
        ->assertDontSee('Create project')
        ->assertNoJavaScriptErrors();
});
