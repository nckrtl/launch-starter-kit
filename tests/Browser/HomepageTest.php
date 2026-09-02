<?php

it('renders the minimal starter homepage without javascript errors', function () {
    $page = visit('/');

    $page->assertSee('Launch Starter Kit')
        ->assertSee('Laravel 13 + React 19 + Inertia v3 + Tailwind CSS v4')
        ->assertDontSee('Launch your next idea')
        ->assertNoJavaScriptErrors();
});
