<?php

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
