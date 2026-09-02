<?php

use App\Providers\ToolbarConfigProvider;
use Inertia\Testing\AssertableInertia as Assert;
use NckRtl\Toolbar\Toolbar;

it('renders the homepage with the Home inertia component', function () {
    $this->get('/')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Home')
        );
});

it('does not ship the launch marketing interface', function () {
    expect(resource_path('js/components/launch'))->not->toBeDirectory()
        ->and(public_path('assets/logo-launch.svg'))->not->toBeFile();
});

it('serves the Agentation runtime alongside the toolbar', function () {
    // The toolbar, and with it the runtime, stays off in console contexts.
    app(Toolbar::class)->config->enabledInConsole = true;

    $this->get('/')
        ->assertSuccessful()
        ->assertSee('/_toolbar-agentation/agentation.js', escape: false)
        ->assertSee('"endpoint":"http://localhost:4747"', escape: false);
})->skip(
    fn (): bool => ! ToolbarConfigProvider::agentationAddonInstalled(),
    'nckrtl/laravel-toolbar-agentation is an optional local addon.',
);
