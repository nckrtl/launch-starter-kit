<?php

use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/website-package-'.bin2hex(random_bytes(6));
    $this->repo = $this->directory.'/repo with spaces';
    mkdir($this->repo.'/scripts', 0755, true);
    mkdir($this->repo.'/node_modules/.bin', 0755, true);
    mkdir($this->directory.'/bin');
    symlink(PHP_BINARY, $this->directory.'/bin/php');
    copy(__DIR__.'/../../scripts/dependencies', $this->repo.'/scripts/dependencies');
    chmod($this->repo.'/scripts/dependencies', 0755);
    foreach ([$this->directory.'/bin/composer', $this->repo.'/node_modules/.bin/vp'] as $binary) {
        copy(__DIR__.'/../Fixtures/dependencies/manager', $binary);
        chmod($binary, 0755);
    }
    file_put_contents($this->repo.'/composer.json', json_encode(['require' => ['livewire/livewire' => '^4.2', 'other/pkg' => '^1.0'], 'require-dev' => ['dev/tool' => '^1.0']]));
    file_put_contents($this->repo.'/package.json', json_encode(['dependencies' => ['react' => '^19.0'], 'devDependencies' => ['@scope/tool' => '^1.0'], 'packageManager' => 'npm@11.12.0']));
    file_put_contents($this->repo.'/vet.json', '{"minimum-release-age":7,"minimum-release-age-exclude":["existing/pkg"]}');
    $this->runDependency = function (array $args, array $fixture = []) {
        file_put_contents($this->directory.'/fixture.json', json_encode($fixture));
        $process = new Process([$this->repo.'/scripts/dependencies', ...$args], $this->directory, [
            'PATH' => $this->directory.'/bin',
            'DEPENDENCY_TEST_LOG' => $this->directory.'/commands',
            'DEPENDENCY_TEST_FIXTURE' => $this->directory.'/fixture.json',
        ]);
        $process->run();

        return $process;
    };
});

afterEach(function () {
    new Process(['rm', '-rf', '--', $this->directory])->mustRun();
});

function livewireFinding(): array
{
    return ['advisories' => ['livewire/livewire' => [
        ['advisoryId' => 'PKSA-xhv3-nr94-h8db', 'title' => 'Livewire advisory one', 'link' => 'https://packagist.org/security-advisories/PKSA-xhv3-nr94-h8db'],
        ['advisoryId' => 'PKSA-bgw4-5zmg-2njg', 'title' => 'Livewire advisory two', 'link' => 'https://packagist.org/security-advisories/PKSA-bgw4-5zmg-2njg'],
    ]]];
}

it('refuses detection and JavaScript updates without executable local Vite+ even with a global CLI', function (array $args, bool $missing) {
    if ($missing) {
        unlink($this->repo.'/node_modules/.bin/vp');
    } else {
        chmod($this->repo.'/node_modules/.bin/vp', 0644);
    }
    copy(__DIR__.'/../Fixtures/dependencies/manager', $this->directory.'/bin/vp');
    chmod($this->directory.'/bin/vp', 0755);
    $manifest = file_get_contents($this->repo.'/package.json');
    $result = ($this->runDependency)($args);

    expect($result->getExitCode())->toBe(127)
        ->and($result->getErrorOutput())->toContain('Missing executable node_modules/.bin/vp; install the repository dependencies first.')
        ->and(file_exists($this->directory.'/commands'))->toBeFalse()
        ->and(file_get_contents($this->repo.'/package.json'))->toBe($manifest);
})->with([
    [['detect']],
    [['package', 'js', '@scope/tool', '2.1.0', 'major']],
    [['package', 'js', '@scope/tool', '1.2.3', 'security', '123']],
])->with([true, false]);

it('can update a Composer-only package without local Vite+', function () {
    unlink($this->repo.'/node_modules/.bin/vp');
    $result = ($this->runDependency)(['package', 'composer', 'dev/tool', '2.0.0', 'major']);

    expect($result->getExitCode())->toBe(0)
        ->and(file_get_contents($this->directory.'/commands'))->toContain('composer|update dev/tool --with-dependencies --no-interaction|');
});

it('detects locked dependencies without vendor and accepts normal audit exit codes', function () {
    expect(is_dir($this->repo.'/vendor'))->toBeFalse();
    $result = ($this->runDependency)(['detect'], [
        'composer outdated --locked --major-only --format=json' => [['data' => ['locked' => [['name' => 'other/pkg', 'version' => '1.0.0', 'latest' => '2.0.0']]]]],
        'vp outdated --format json' => [['code' => 1, 'data' => ['react' => ['current' => '19.0.0', 'latest' => '20.0.0'], 'unchanged' => ['current' => '1.0.0', 'latest' => '1.1.0']]]],
        'composer audit --locked --format=json' => [['code' => 1, 'data' => livewireFinding()]],
        'vp pm audit --json' => [['code' => 1, 'data' => ['vulnerabilities' => ['react' => ['via' => [['source' => 123, 'url' => 'https://github.com/advisories/GHSA-test', 'title' => 'test', 'range' => '<20']]]]]]],
    ]);
    expect($result->getExitCode())->toBe(0);
    $rows = json_decode($result->getOutput(), true, flags: JSON_THROW_ON_ERROR)['candidates'];
    expect(array_column($rows, 'package'))->toBe(['livewire/livewire', 'other/pkg', 'react'])
        ->and($rows[0]['advisories'])->toHaveCount(2)
        ->and($rows[2]['reasons'])->toBe(['major', 'security']);
    $log = file_get_contents($this->directory.'/commands');
    expect($log)->toContain('composer|outdated --locked --major-only --format=json|', 'composer|audit --locked --format=json|', 'vp|outdated --format json|', 'vp|pm audit --json|');
    expect(is_dir($this->repo.'/vendor'))->toBeFalse();
    expect(json_decode(file_get_contents($this->repo.'/composer.json'), true)['require']['livewire/livewire'])->toBe('^4.2');
});

it('fails closed on malformed, unsupported or operational detection output', function (array $response) {
    $result = ($this->runDependency)(['detect'], ['composer outdated --locked --major-only --format=json' => [$response]]);
    expect($result->getExitCode())->toBe(70)->and($result->getOutput())->toBe('');
})->with([[['raw' => 'not json']], [['code' => 4, 'data' => ['locked' => []]]], [['data' => ['error' => 'offline']]], [['data' => []]]]);

it('updates only the chosen direct range and uses a targeted Composer update', function () {
    $result = ($this->runDependency)(['package', 'composer', 'dev/tool', '2.0.0', 'major']);
    expect($result->getExitCode())->toBe(0);
    $manifest = json_decode(file_get_contents($this->repo.'/composer.json'), true);
    expect($manifest['require-dev']['dev/tool'])->toBe('^2.0.0')->and($manifest['require']['other/pkg'])->toBe('^1.0');
    expect(file_get_contents($this->directory.'/commands'))->toContain('composer|require dev/tool:^2.0.0 --no-update --no-interaction --dev|', 'composer|update dev/tool --with-dependencies --no-interaction|');
});

it('keeps the JavaScript dependency section and delegates the range bump to vp', function () {
    $result = ($this->runDependency)(['package', 'js', '@scope/tool', '2.1.0', 'major']);
    expect($result->getExitCode())->toBe(0)
        ->and(json_decode(file_get_contents($this->repo.'/package.json'), true)['devDependencies']['@scope/tool'])->toBe('^2.1.0');
    expect(file_get_contents($this->directory.'/commands'))->toContain('vp|add @scope/tool@^2.1.0 --save-dev|');
});

it('starts a Composer security update without vendor and proves both locked Livewire advisories cleared', function () {
    expect(is_dir($this->repo.'/vendor'))->toBeFalse();
    $result = ($this->runDependency)(['package', 'composer', 'livewire/livewire', '4.9.9', 'security', 'PKSA-xhv3-nr94-h8db', 'PKSA-bgw4-5zmg-2njg'], [
        'composer audit --locked --format=json' => [['code' => 1, 'data' => livewireFinding()], ['data' => ['advisories' => []]]],
    ]);
    expect($result->getExitCode())->toBe(0);
    expect(substr_count(file_get_contents($this->directory.'/commands'), 'composer|audit --locked --format=json|'))->toBe(2);
    expect(json_decode(file_get_contents($this->repo.'/vet.json'), true))->toBe(['minimum-release-age' => 7, 'minimum-release-age-exclude' => ['existing/pkg', 'livewire/livewire']]);
    expect(json_decode(file_get_contents($this->repo.'/composer.json'), true)['require']['livewire/livewire'])->toBe('4.9.9');
});

it('refuses an unobserved advisory before any manifest or policy write', function () {
    $original = file_get_contents($this->repo.'/vet.json');
    $result = ($this->runDependency)(['package', 'composer', 'livewire/livewire', '4.9.9', 'security', 'PKSA-wrong'], [
        'composer audit --locked --format=json' => [['code' => 1, 'data' => livewireFinding()]],
    ]);
    expect($result->getExitCode())->toBe(64)->and(file_get_contents($this->repo.'/vet.json'))->toBe($original);
    expect(file_get_contents($this->directory.'/commands'))->not->toContain('require');
});

it('fails if the selected package still has any advisory after update', function () {
    $result = ($this->runDependency)(['package', 'composer', 'livewire/livewire', '4.9.9', 'security', 'PKSA-xhv3-nr94-h8db'], [
        'composer audit --locked --format=json' => [['code' => 1, 'data' => livewireFinding()]],
    ]);
    expect($result->getExitCode())->toBe(1)->and($result->getErrorOutput())->toContain('still has security advisories');
});

it('rejects multiple, injected, non-direct and floating targets before calling a manager', function (array $args) {
    $result = ($this->runDependency)($args);
    expect($result->getExitCode())->toBe(64)->and(file_exists($this->directory.'/commands'))->toBeFalse();
})->with([
    [['package', 'composer', 'livewire/livewire other/pkg', '5.0.0', 'major']],
    [['package', 'composer', '--help', '5.0.0', 'major']],
    [['package', 'composer', 'missing/pkg', '5.0.0', 'major']],
    [['package', 'js', 'react', 'latest', 'major']],
    [['package', 'js', 'react', '20.0.0', 'major', 'extra']],
    [['package', 'composer', 'livewire/livewire', '4.9.9', 'security']],
]);

it('uses vp for an exact npm security fix and preserves the existing dependency section', function () {
    $result = ($this->runDependency)(['package', 'js', '@scope/tool', '1.2.3', 'security', '123'], [
        'vp pm audit --json' => [
            ['code' => 1, 'data' => ['vulnerabilities' => ['@scope/tool' => ['via' => [['source' => 123, 'url' => 'https://github.com/advisories/GHSA-test']]]]]],
            ['data' => ['vulnerabilities' => []]],
        ],
    ]);
    expect($result->getExitCode())->toBe(0)
        ->and(file_get_contents($this->directory.'/commands'))->toContain('vp|add @scope/tool@1.2.3 --save-dev --save-exact -- --min-release-age=0|')
        ->and(json_decode(file_get_contents($this->repo.'/package.json'), true)['devDependencies']['@scope/tool'])->toBe('1.2.3');
});

it('can reconcile a repeated security command without inventing an advisory or duplicating a Vet exclusion', function () {
    $fixture = ['composer audit --locked --format=json' => [['code' => 1, 'data' => livewireFinding()], ['data' => ['advisories' => []]]]];
    $args = ['package', 'composer', 'livewire/livewire', '4.9.9', 'security', 'PKSA-xhv3-nr94-h8db'];
    expect(($this->runDependency)($args, $fixture)->getExitCode())->toBe(0);
    expect(($this->runDependency)($args, $fixture)->getExitCode())->toBe(0);
    expect(json_decode(file_get_contents($this->repo.'/vet.json'), true)['minimum-release-age-exclude'])->toBe(['existing/pkg', 'livewire/livewire']);
});

it('stops before the targeted update when the range bump fails', function () {
    $result = ($this->runDependency)(['package', 'composer', 'dev/tool', '2.0.0', 'major'], [
        'composer require dev/tool:^2.0.0 --no-update --no-interaction --dev' => [['code' => 17, 'raw' => 'cannot change manifest']],
    ]);
    expect($result->getExitCode())->toBe(17)->and(file_get_contents($this->directory.'/commands'))->not->toContain('composer|update');
});

it('refuses aliased and ambiguous packages before manager calls', function () {
    file_put_contents($this->repo.'/package.json', json_encode(['dependencies' => ['vite' => 'npm:some-alias@1.0.0'], 'devDependencies' => ['react' => '^19'], 'optionalDependencies' => ['react' => '^19']]));
    foreach (['vite', 'react'] as $name) {
        expect(($this->runDependency)(['package', 'js', $name, '2.0.0', 'major'])->getExitCode())->toBe(64);
    }
    expect(file_exists($this->directory.'/commands'))->toBeFalse();
});

it('does not introduce an age exclusion when reconciling an already-fixed exact target', function () {
    $manifest = json_decode(file_get_contents($this->repo.'/composer.json'), true);
    $manifest['require']['livewire/livewire'] = '4.9.9';
    file_put_contents($this->repo.'/composer.json', json_encode($manifest));
    $before = file_get_contents($this->repo.'/vet.json');
    $result = ($this->runDependency)(['package', 'composer', 'livewire/livewire', '4.9.9', 'security', 'PKSA-xhv3-nr94-h8db'], [
        'composer audit --locked --format=json' => [['data' => ['advisories' => []]]],
    ]);
    expect($result->getExitCode())->toBe(0)->and(file_get_contents($this->repo.'/vet.json'))->toBe($before);
});

describe('npm indirect advisory chains', function () {
    beforeEach(function () {
        // The reachable shadcn graph from this repository's npm audit on 2026-10-25.
        $this->audit = json_decode(file_get_contents(__DIR__.'/../Fixtures/dependencies/npm-audit-shadcn.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest = json_decode(file_get_contents($this->repo.'/package.json'), true);
        $manifest['dependencies']['shadcn'] = '^4.1.0';
        file_put_contents($this->repo.'/package.json', json_encode($manifest));
        $this->detectFixture = [
            'composer outdated --locked --major-only --format=json' => [['data' => ['locked' => []]]],
            'vp outdated --format json' => [['data' => []]],
            'composer audit --locked --format=json' => [['data' => ['advisories' => []]]],
        ];
    });

    it('attaches the real Zod advisory to shadcn once through both direct and nested via paths', function () {
        $result = ($this->runDependency)(['detect'], [
            ...$this->detectFixture,
            'vp pm audit --json' => [['code' => 1, 'data' => $this->audit]],
        ]);

        expect($result->getExitCode())->toBe(0);
        $rows = json_decode($result->getOutput(), true, flags: JSON_THROW_ON_ERROR)['candidates'];
        expect(array_column($rows, 'package'))->toBe(['shadcn'])
            ->and($rows[0]['advisories'])->toBe([[
                'id' => '1243066',
                'url' => 'https://github.com/advisories/GHSA-mrhx-c7xc-m3w5',
                'title' => 'Zod can exhaust memory while validating oversized constrained collections',
                'affected' => '<4.6.6',
                'severity' => 'high',
            ]]);
    });

    it('accepts the real advisory ID and verifies that it cleared from the selected direct dependency', function (bool $cleared) {
        $after = $this->audit;
        if ($cleared) {
            unset($after['vulnerabilities']['shadcn'], $after['vulnerabilities']['@shadcn/registry']);
            // Zod remains affected through another direct dependency, outside this update.
            $after['vulnerabilities']['react'] = ['via' => ['zod'], 'isDirect' => true];
        }
        $result = ($this->runDependency)(['package', 'js', 'shadcn', '4.21.6', 'security', '1243066'], [
            'vp pm audit --json' => [['code' => 1, 'data' => $this->audit], ['code' => 1, 'data' => $after]],
        ]);

        expect($result->getExitCode())->toBe($cleared ? 0 : 1)
            ->and(file_get_contents($this->directory.'/commands'))->toContain('vp|add shadcn@4.21.6 --save-prod --save-exact -- --min-release-age=0|');
        $manifest = json_decode(file_get_contents($this->repo.'/package.json'), true);
        expect($manifest['dependencies'])->toBe(['react' => '^19.0', 'shadcn' => '4.21.6']);
        if (! $cleared) {
            expect($result->getErrorOutput())->toContain('The selected package still has security advisories');
        }
    })->with([true, false]);

    it('rejects a package-name placeholder before updating', function () {
        $before = file_get_contents($this->repo.'/package.json');
        $result = ($this->runDependency)(['package', 'js', 'shadcn', '4.21.6', 'security', 'shadcn'], [
            'vp pm audit --json' => [['code' => 1, 'data' => $this->audit]],
        ]);

        expect($result->getExitCode())->toBe(64)
            ->and(file_get_contents($this->directory.'/commands'))->not->toContain('vp|add')
            ->and(file_get_contents($this->repo.'/package.json'))->toBe($before);
    });

    it('retains a shared advisory on every controlling direct dependency', function () {
        $this->audit['vulnerabilities']['react'] = ['via' => ['zod'], 'isDirect' => true];
        $result = ($this->runDependency)(['detect'], [
            ...$this->detectFixture,
            'vp pm audit --json' => [['code' => 1, 'data' => $this->audit]],
        ]);

        expect($result->getExitCode())->toBe(0);
        $rows = json_decode($result->getOutput(), true, flags: JSON_THROW_ON_ERROR)['candidates'];
        expect(array_column($rows, 'package'))->toBe(['react', 'shadcn'])
            ->and(array_column($rows[0]['advisories'], 'id'))->toBe(['1243066'])
            ->and($rows[1]['advisories'])->toBe($rows[0]['advisories']);
    });

    it('fails closed on missing references or chains without a resolvable advisory', function (array $via) {
        $this->audit['vulnerabilities']['shadcn']['via'] = $via;
        $this->audit['vulnerabilities']['@shadcn/registry']['via'] = ['shadcn'];
        $result = ($this->runDependency)(['detect'], [
            ...$this->detectFixture,
            'vp pm audit --json' => [['code' => 1, 'data' => $this->audit]],
        ]);

        expect($result->getExitCode())->toBe(70)->and($result->getOutput())->toBe('');
    })->with([[['missing-package']], [['@shadcn/registry']], [[]]]);
});

describe('bun through Vite+', function () {
    beforeEach(function () {
        file_put_contents($this->repo.'/package.json', json_encode([
            'dependencies' => [
                '@nckrtl/launch-ui' => '0.0.x',
                'shadcn' => '^4.21.0',
                'react' => '^19.0',
            ],
            'devDependencies' => [
                'vite' => 'npm:@voidzero-dev/vite-plus-core@0.2.8',
                'vite-plus' => '^0.2.9',
            ],
            'packageManager' => 'bun@1.3.14',
        ]));
        file_put_contents($this->repo.'/bun.lock', <<<'JSON'
{
  "lockfileVersion": 1,
  "packages": {
    "@nckrtl/launch-ui": ["@nckrtl/launch-ui@0.0.31", "", {"dependencies": {"@storybook/addon-mcp": "1.0.0"}, "peerDependencies": {"vite-plus": "^0.2.0"}}, "sha"],
    "@storybook/addon-mcp": ["@storybook/addon-mcp@1.0.0", "", {"peerDependencies": {"storybook": "^10.0.0"}}, "sha"],
    "storybook": ["storybook@10.5.6", "", {}, "sha"],
    "shadcn": ["shadcn@4.21.0", "", {"dependencies": {"zod": "^3.0.0"}}, "sha"],
    "zod": ["zod@3.25.76", "", {}, "sha"],
    "vite-plus": ["vite-plus@0.2.9", "", {}, "sha"],
    "vite": ["@voidzero-dev/vite-plus-core@0.2.8", "", {}, "sha"],
  },
}
JSON);
        $this->bunDetect = [
            'composer outdated --locked --major-only --format=json' => [['data' => ['locked' => []]]],
            'composer audit --locked --format=json' => [['data' => ['advisories' => []]]],
            'vp outdated' => [['raw' => implode("\n", [
                'bun outdated v1.3.14',
                '|----------------------------------------------------------------|',
                '| Package                            | Current | Update | Latest |',
                '|------------------------------------|---------|--------|--------|',
                '| @nckrtl/launch-ui                  | 0.0.31  | 0.0.33 | 0.1.0  |',
                '|------------------------------------|---------|--------|--------|',
                '| shadcn                             | 4.21.0  | 4.22.1 | 4.22.1 |',
                '|------------------------------------|---------|--------|--------|',
                '| @voidzero-dev/vite-plus-core (dev) | 0.2.8   | 0.2.8  | 1.4.0  |',
                '|------------------------------------|---------|--------|--------|',
                '| vite-plus (dev)                    | 0.2.9   | 0.2.10 | 0.2.10 |',
                '|----------------------------------------------------------------|',
            ])]],
            'vp pm audit --json' => [['code' => 1, 'data' => [
                'zod' => [['id' => 1243066, 'url' => 'https://github.com/advisories/GHSA-mrhx-c7xc-m3w5', 'title' => 'Zod', 'severity' => 'high', 'vulnerable_versions' => '<4.6.6']],
                'storybook' => [['id' => 999, 'url' => 'https://github.com/advisories/GHSA-story', 'title' => 'Storybook', 'severity' => 'moderate', 'vulnerable_versions' => '<10.5.7']],
                'vite-plus' => [['id' => 111, 'url' => 'https://github.com/advisories/GHSA-vp', 'title' => 'vite-plus', 'severity' => 'low', 'vulnerable_versions' => '<0.3.0']],
            ]]],
        ];
    });

    it('reads the bun table and attaches indirect advisories to the controlling direct dependency', function () {
        $result = ($this->runDependency)(['detect'], $this->bunDetect);

        expect($result->getExitCode())->toBe(0);
        $rows = json_decode($result->getOutput(), true, flags: JSON_THROW_ON_ERROR)['candidates'];
        expect(array_column($rows, 'package'))->toBe(['@nckrtl/launch-ui', 'shadcn', 'vite', 'vite-plus']);
        expect($rows[0]['reasons'])->toBe(['major', 'security'])
            ->and(array_column($rows[0]['advisories'], 'id'))->toBe(['999'])
            ->and($rows[0]['current'])->toBe('0.0.31')
            ->and($rows[0]['latest'])->toBe('0.1.0');
        expect($rows[1]['reasons'])->toBe(['security'])
            ->and($rows[1]['advisories'][0]['id'])->toBe('1243066')
            ->and($rows[1]['advisories'][0]['severity'])->toBe('high');
        expect($rows[2]['reasons'])->toBe(['major'])
            ->and($rows[2]['current'])->toBe('0.2.8')
            ->and($rows[2]['latest'])->toBe('1.4.0')
            ->and($rows[2]['advisories'])->toBe([]);
        expect(array_column($rows[3]['advisories'], 'id'))->toBe(['111']);
        expect(file_get_contents($this->directory.'/commands'))->toContain('vp|outdated|')
            ->and(file_get_contents($this->directory.'/commands'))->not->toContain('vp|outdated --format json|');
    });

    it('fails closed when a bun advisory is not reachable from a direct dependency', function () {
        $fixture = $this->bunDetect;
        $fixture['vp pm audit --json'][0]['data']['ghost'] = [['id' => 5, 'url' => 'https://example.test/ghost', 'title' => 'ghost']];
        $result = ($this->runDependency)(['detect'], $fixture);

        expect($result->getExitCode())->toBe(70)->and($result->getOutput())->toBe('')
            ->and($result->getErrorOutput())->toContain('Cannot attach bun advisory to a direct dependency: ghost');
    });

    it('rejects a bun outdated result that is not the table', function () {
        $fixture = $this->bunDetect;
        $fixture['vp outdated'] = [['raw' => 'not a table']];
        $result = ($this->runDependency)(['detect'], $fixture);

        expect($result->getExitCode())->toBe(70)->and($result->getErrorOutput())->toContain('Unsupported Bun outdated output');
    });

    it('pins a bun security fix through vp without an npm age bypass', function (bool $cleared) {
        $after = $cleared ? [] : ['zod' => [['id' => 1243066, 'url' => 'https://github.com/advisories/GHSA-mrhx-c7xc-m3w5', 'title' => 'Zod', 'vulnerable_versions' => '<4.6.6']]];
        $result = ($this->runDependency)(['package', 'js', 'shadcn', '4.21.6', 'security', '1243066'], [
            'vp pm audit --json' => [
                ['code' => 1, 'data' => ['zod' => [['id' => 1243066, 'url' => 'https://github.com/advisories/GHSA-mrhx-c7xc-m3w5', 'title' => 'Zod', 'severity' => 'high', 'vulnerable_versions' => '<4.6.6']]]],
                ['code' => 1, 'data' => $after],
            ],
        ]);

        expect($result->getExitCode())->toBe($cleared ? 0 : 1)
            ->and(file_get_contents($this->directory.'/commands'))->toContain('vp|add shadcn@4.21.6 --save-prod --save-exact|')
            ->and(file_get_contents($this->directory.'/commands'))->not->toContain('min-release-age');
        if (! $cleared) {
            expect($result->getErrorOutput())->toContain('The selected package still has security advisories');
        }
    })->with([true, false]);

    it('refuses to rewrite the vite alias before calling a manager', function () {
        $result = ($this->runDependency)(['package', 'js', 'vite', '1.4.0', 'major']);

        expect($result->getExitCode())->toBe(64)->and(file_exists($this->directory.'/commands'))->toBeFalse();
    });
});

it('does not invent a Composer age policy when vet.json is absent', function () {
    unlink($this->repo.'/vet.json');
    $result = ($this->runDependency)(['package', 'composer', 'livewire/livewire', '4.9.9', 'security', 'PKSA-xhv3-nr94-h8db'], [
        'composer audit --locked --format=json' => [['code' => 1, 'data' => livewireFinding()], ['data' => ['advisories' => []]]],
    ]);

    expect($result->getExitCode())->toBe(0)
        ->and(file_exists($this->repo.'/vet.json'))->toBeFalse()
        ->and(json_decode(file_get_contents($this->repo.'/composer.json'), true)['require']['livewire/livewire'])->toBe('4.9.9');
});
