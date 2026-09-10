<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Cache::forget('commander:hermes:kanban:latest:v1');
    Process::preventStrayProcesses();
});

it('opens unified Kanban and combines project and agent filters', function () {
    Cache::put('commander:hermes:kanban:v1', [
        'status' => 'online', 'fetched_at' => now()->toIso8601String(), 'unavailable' => [],
        'boards' => [['slug' => 'launch', 'name' => 'Launch'], ['slug' => 'orbit', 'name' => 'Orbit']],
        'cards' => [
            ['id' => '1', 'title' => 'Tom launch task', 'status' => 'running', 'assignee' => 'tom', 'project' => 'launch', 'project_name' => 'Launch'],
            ['id' => '1', 'title' => 'Anna orbit task', 'status' => 'ready', 'assignee' => 'anna', 'project' => 'orbit', 'project_name' => 'Orbit'],
            ['id' => '2', 'title' => 'Tom orbit task', 'status' => 'ready', 'assignee' => 'tom', 'project' => 'orbit', 'project_name' => 'Orbit'],
        ],
    ], 120);
    visit('/projects')->click('Agents')->assertPathIs('/agents')
        ->assertSee('Tom launch task')->assertSee('Anna orbit task')->assertSee('Tom orbit task')
        ->assertMissing('section[aria-label="Archived"]')->assertMissing('section[aria-label="Blocked"]')
        ->select('[aria-label="Agent"]', 'tom')->assertDontSee('Anna orbit task')
        ->select('[aria-label="Project"]', 'orbit')->assertDontSee('Tom launch task')->assertSee('Tom orbit task')
        ->select('[aria-label="Agent"]', 'anna')->assertSee('Anna orbit task')->assertDontSee('Tom orbit task')
        ->select('[aria-label="Project"]', 'launch')->assertSee('No cards match these filters.')
        ->assertNoJavaScriptErrors();
});

it('shows an unavailable state without claiming the board is empty', function () {
    Cache::put('commander:hermes:kanban:v1', [
        'status' => 'unavailable', 'fetched_at' => now()->toIso8601String(), 'boards' => [], 'cards' => [], 'unavailable' => [],
    ], 120);
    visit('/agents')->assertSee('Hermes is unavailable')->assertDontSee('No cards match these filters.')->assertNoJavaScriptErrors();
});

it('shows fixed lanes with placeholders before card data arrives', function () {
    Route::get('/kanban-loading-test', fn () => inertia('Agents/Kanban'));
    visit('/kanban-loading-test')
        ->assertSee('To do')->assertSee('Blocked')->assertSee('Archived')
        ->assertAttribute('[aria-label="Hermes Kanban board"]', 'aria-busy', 'true')
        ->assertPresent('[aria-label="Loading To do cards"] [data-slot="skeleton"]')
        ->assertPresent('[aria-label="Loading Blocked cards"] [data-slot="skeleton"]')
        ->assertPresent('[aria-label="Loading Archived cards"] [data-slot="skeleton"]')
        ->assertDontSee('Show empty columns')->assertNoJavaScriptErrors();
});

it('layers dark Kanban cards above lanes above the page background', function () {
    Cache::put('commander:hermes:kanban:v1', [
        'status' => 'online', 'fetched_at' => now()->toIso8601String(), 'unavailable' => [],
        'boards' => [['slug' => 'launch', 'name' => 'Launch']],
        'cards' => [['id' => '1', 'title' => 'Contrast check', 'status' => 'running', 'assignee' => 'tom', 'project' => 'launch', 'project_name' => 'Launch']],
    ], 120);
    $page = visit('/agents')->inDarkMode()->assertSee('Contrast check')->assertNoJavaScriptErrors();
    expect($page->script('getComputedStyle(document.body).backgroundColor'))->toBe('oklch(0.21 0 0)')
        ->and($page->script('getComputedStyle(document.querySelector("section[aria-label=Running]")).backgroundColor'))->toBe('oklch(0.25 0 0)')
        ->and($page->script('getComputedStyle(document.querySelector("section [data-slot=card]")).backgroundColor'))->toBe('oklch(0.31 0 0)');
});
