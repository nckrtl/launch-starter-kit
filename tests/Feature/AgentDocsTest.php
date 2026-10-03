<?php

it('serves llms.txt as plain text', function () {
    $this->get('/llms.txt')
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8');
});

it('points agents from llms.txt to the full setup guide', function () {
    expect($this->get('/llms.txt')->getContent())
        ->toContain('https://launch.nckrtl.com/create.md');
});

it('lists every local environment addendum in llms.txt', function () {
    expect($this->get('/llms.txt')->getContent())
        ->toContain('https://launch.nckrtl.com/herd.md')
        ->toContain('https://launch.nckrtl.com/orbit.md')
        ->toContain('https://launch.nckrtl.com/solo.md');
});

it('links the conventions document from llms.txt and the end of setup', function () {
    expect($this->get('/llms.txt')->getContent())
        ->toContain('https://launch.nckrtl.com/conventions.md');

    // An agent that only fetches create.md must still be told where the rules are.
    expect($this->get('/create.md')->getContent())
        ->toContain('https://launch.nckrtl.com/conventions.md');
});

it('serves the conventions separately from the setup guide', function () {
    $conventions = $this->get('/conventions.md');

    $conventions->assertSuccessful()
        ->assertHeader('Content-Type', 'text/markdown; charset=utf-8');

    expect($conventions->getContent())
        ->toContain('routes/web.php')
        ->toContain('Wayfinder')
        ->toContain('@radix-ui')
        ->toContain('Quality gates');

    // The setup guide keeps the setup steps and sheds the rules.
    $setup = $this->get('/create.md')->getContent();

    expect($setup)->toContain('composer create-project')
        ->and($setup)->not->toContain('Quality gates')
        ->and($setup)->not->toContain('bunx shadcn add');
});

it('serves the setup guide as markdown', function () {
    $this->get('/create.md')
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'text/markdown; charset=utf-8');
});

it('includes the non-interactive setup commands in the guide', function () {
    $content = $this->get('/create.md')->getContent();

    expect($content)
        ->toContain('composer create-project')
        ->toContain('bun run build')
        // Dist/path create-project has no VCS; hooks need an explicit git repo.
        ->toContain('git init')
        // `composer setup` is interactive, so agents must be steered away from it.
        ->toContain('Do not run `composer setup`');
});

it('tells agents to install the Playwright Chromium binary', function () {
    // `bun install` brings in the playwright npm package, but the browser binary is a
    // separate download. Browser tests — and so `composer check` — fail without it.
    expect($this->get('/create.md')->getContent())
        ->toContain('bunx playwright install chromium');

    expect($this->get('/conventions.md')->getContent())
        ->toContain('bunx playwright install chromium');
});

it('makes the guide branch on the detected environment before writing .env', function () {
    $content = $this->get('/create.md')->getContent();

    $detection = strpos($content, 'command -v orbit');
    $envConfig = strpos($content, 'VITE_APP_URL=http://localhost:8000');

    expect($detection)->not->toBeFalse()
        ->and($envConfig)->not->toBeFalse()
        // Detection has to come first, or the agent writes the wrong URLs and redoes the work.
        ->and($detection)->toBeLessThan($envConfig);

    expect($content)
        ->toContain('https://launch.nckrtl.com/herd.md')
        ->toContain('https://launch.nckrtl.com/orbit.md');
});

it('gives agents a parallel plan with an accurate critical path', function () {
    $content = $this->get('/create.md')->getContent();

    expect($content)
        ->toContain('If you can spawn subagents')
        // VITE_APP_NAME is read from .env and baked into the bundle, so the build
        // genuinely waits on the .env join rather than only on `bun install`.
        ->toContain('VITE_APP_NAME')
        ->toContain('What must stay serial');

    // The plan must appear before the steps it reorders.
    $plan = strpos($content, 'If you can spawn subagents');
    $firstStep = strpos($content, '## 1. Create the project');

    expect($plan)->toBeLessThan($firstStep);
});

it('warns that composer dev never exits', function () {
    expect($this->get('/create.md')->getContent())
        ->toContain('It does not exit');
});

it('serves an addendum per supported environment', function (string $uri, string $mustContain) {
    $response = $this->get($uri);

    $response->assertSuccessful()
        ->assertHeader('Content-Type', 'text/markdown; charset=utf-8');

    expect($response->getContent())->toContain($mustContain);
})->with([
    ['/herd.md', 'herd secure'],
    ['/orbit.md', 'orbit instance:register'],
    ['/solo.md', 'mcp__solo__start_all_commands'],
]);

it('tells agents to detect Solo from their tool list, not the shell', function () {
    expect($this->get('/solo.md')->getContent())
        ->toContain('mcp__solo__')
        ->toContain('not a shell check');

    expect($this->get('/create.md')->getContent())->toContain('mcp__solo__');
});

it('ships no solo.yml, leaving it to the setup flow', function () {
    // Orbit's runtime units inject VITE_DEV_SERVER_KEY/CERT. A shipped solo.yml
    // declaring those commands would be wrong for every Orbit user, and the kit
    // cannot know which environment it lands in. The agent writes it if needed.
    expect(file_exists(base_path('solo.yml')))->toBeFalse();

    expect($this->get('/solo.md')->getContent())
        ->toContain('does not ship a `solo.yml`');
});

it('gives process ownership to Orbit whenever Orbit is present', function () {
    expect($this->get('/solo.md')->getContent())
        ->toContain('VITE_DEV_SERVER_KEY')
        ->toContain('command -v orbit');

    expect($this->get('/orbit.md')->getContent())
        ->toContain('orbit process:add')
        ->toContain('VITE_DEV_SERVER_CERT')
        // Orbit users must not duplicate the same commands in solo.yml.
        ->toContain('solo.yml');

    expect($this->get('/create.md')->getContent())
        ->toContain('orbit process:add')
        ->toContain('no** `solo.yml`');
});

it('tells both environments not to start a second PHP server', function (string $uri) {
    expect($this->get($uri)->getContent())
        ->toContain('Do not run')
        ->toContain('php artisan serve')
        ->toContain('composer dev');
})->with(['/herd.md', '/orbit.md']);

it('does not send agents to the non-existent orbit link command', function () {
    foreach (['/create.md', '/herd.md'] as $uri) {
        expect($this->get($uri)->getContent())->not->toContain('orbit link');
    }

    // The Orbit page may only mention it to warn against it: `orbit link` prints the
    // help listing and exits 0, so an agent would read the failure as success.
    expect($this->get('/orbit.md')->getContent())
        ->toContain('There is no `orbit link` command');
});

it('advertises the setup guide to agents from the homepage head', function () {
    $this->get('/')
        ->assertSuccessful()
        ->assertSee('rel="alternate" type="text/markdown" href="/create.md"', escape: false);
});
