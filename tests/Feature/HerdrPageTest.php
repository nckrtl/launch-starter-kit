<?php

it('renders the Herdr page and defers session reads', function () {
    config(['commander.herdr.sessions' => []]);
    $this->get('/herdr')->assertSuccessful()->assertInertia(fn ($page) => $page
        ->component('Herdr/Index')->missing('fleet')
        ->loadDeferredProps(fn ($reload) => $reload->has('fleet.sessions', 0)->has('fleet.fetched_at')));
});

it('reports unreachable Herdr sessions without failing the page', function () {
    config(['commander.herdr.sessions' => [['node' => 'test-node', 'name' => 'offline-session', 'socket' => '/nonexistent/commander-herdr-test.sock']]]);
    $this->get('/herdr')->assertSuccessful()->assertInertia(fn ($page) => $page
        ->component('Herdr/Index')->loadDeferredProps(fn ($reload) => $reload
        ->where('fleet.sessions.0.node', 'test-node')->where('fleet.sessions.0.status', 'unavailable')));
});
