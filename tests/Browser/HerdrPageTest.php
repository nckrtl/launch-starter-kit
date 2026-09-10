<?php

use Illuminate\Support\Facades\Route;

it('opens Herdr from the sidebar and shows session agents and workspace labels', function () {
    Route::get('/herdr', fn () => inertia('Herdr/Index', ['fleet' => [
        'fetched_at' => now()->toIso8601String(),
        'sessions' => [[
            'node' => 'beast', 'name' => 'launch', 'status' => 'online',
            'workspaces' => [['id' => 'ws1', 'label' => 'Starter Kit']],
            'agents' => [['name' => 'dependency-reviewer', 'kind' => 'codex', 'status' => 'working', 'workspace_id' => 'ws1']],
        ], [
            'node' => 'mini', 'name' => 'launch', 'status' => 'online',
            'workspaces' => [['id' => 'ws1', 'label' => 'Starter Kit']],
            'agents' => [['name' => 'mini-reviewer', 'kind' => 'claude', 'status' => 'idle', 'workspace_id' => 'ws1']],
        ], [
            'node' => 'beast', 'name' => 'orbit', 'status' => 'online',
            'workspaces' => [['id' => 'ws1', 'label' => 'Orbit']],
            'agents' => [['name' => 'orbit-reviewer', 'kind' => 'codex', 'status' => 'done', 'workspace_id' => 'ws1']],
        ]],
    ]]));
    $page = visit('/projects')->click('Herdr')->assertPathIs('/herdr')
        ->assertSee('beast')->assertSee('dependency-reviewer')->assertSee('Starter Kit')
        ->assertSee('working')->assertSee('codex')->assertNoJavaScriptErrors();
    $page->assertSee('mini-reviewer')->assertSee('orbit-reviewer')
        ->select('select[aria-label="Machine"]', 'beast')->assertDontSee('mini-reviewer')->assertSee('orbit-reviewer')
        ->select('select[aria-label="Project / workspace"]', 'Starter Kit')->assertSee('dependency-reviewer')->assertDontSee('orbit-reviewer')
        ->select('select[aria-label="Machine"]', 'mini')->assertSee('mini-reviewer')->assertDontSee('dependency-reviewer')
        ->select('select[aria-label="Project / workspace"]', 'Orbit')->assertSee('No agents match these filters')
        ->select('select[aria-label="Machine"]', '')->select('select[aria-label="Project / workspace"]', '')
        ->assertSee('dependency-reviewer')->assertSee('mini-reviewer')->assertSee('orbit-reviewer')
        ->assertNoJavaScriptErrors();
    expect($page->script('document.querySelectorAll("table").length'))->toBe(1);
    expect($page->script('Array.from(document.querySelectorAll("style")).some(style => style.textContent.includes("#nprogress .bar") && style.textContent.includes("#ffffff"))'))->toBeTrue();
    $page->assertAttribute('[data-slot=commander-logo]', 'viewBox', '0 0 440 440');
    expect($page->script('document.querySelector("link[rel=icon]").getAttribute("href")'))->toBe('/commander-icon.svg');
    expect($page->script('getComputedStyle(document.querySelector("[data-slot=commander-logo]").parentElement).backgroundColor'))->toBe('rgb(255, 255, 255)');
    expect($page->script('Array.from(document.querySelectorAll("[data-slot=native-select-wrapper]")).every(wrapper => { const icon = wrapper.querySelector("svg").getBoundingClientRect(); const select = wrapper.querySelector("select").getBoundingClientRect(); return select.right - icon.right >= 9 && getComputedStyle(wrapper.querySelector("select")).appearance === "none"; })'))->toBeTrue();
    expect($page->script('document.querySelector("[aria-label=\"Agent filters\"]").getBoundingClientRect().bottom <= document.querySelector("table").getBoundingClientRect().top'))->toBeTrue();
    expect($page->script('Array.from(document.querySelectorAll("thead th")).map(th => th.textContent)'))
        ->toBe(['Agent', 'Type', 'Workspace', 'Status', 'Machine']);
});

it('shows an empty configuration state on Herdr', function () {
    config(['commander.herdr.sessions' => []]);
    visit('/herdr')->assertSee('No Herdr sessions configured')->assertNoJavaScriptErrors();
});

it('shows unavailable Herdr sessions without calling them empty', function () {
    config(['commander.herdr.sessions' => [['node' => 'test-node', 'name' => 'offline-session', 'socket' => '/nonexistent/commander-herdr-test.sock']]]);
    visit('/herdr')->assertSee('test-node / offline-session')->assertSee('unavailable')
        ->assertDontSee('No agents in this session.')->assertNoJavaScriptErrors();
});
