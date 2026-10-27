<?php

use Inertia\Testing\AssertableInertia as Assert;

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
