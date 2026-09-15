<?php

use App\Support\Csp\Presets\Basic;
use App\Support\Csp\Presets\Development;
use Spatie\Csp\Policy;

function developmentPolicy(): Policy
{
    $policy = new Policy;

    (new Development)->configure($policy);

    return $policy;
}

it('allows only the configured Orbit Herdr observer origins', function (): void {
    config([
        'commander.orbit.herdr_observer_origins' => [
            'wss://commander-tasks.herdr.beast.test',
            'wss://commander-tasks.herdr.sabre.orbit',
            'wss://attacker.test/path',
            'wss://attacker.test?target=commander',
            'wss://user@attacker.test',
            'https://attacker.test',
            'wss://good.test; script-src *',
            "wss://good.test\nscript-src *",
        ],
    ]);
    $policy = new Policy;
    (new Basic)->configure($policy);

    expect($policy->getContents())
        ->toContain('wss://commander-tasks.herdr.beast.test')
        ->toContain('wss://commander-tasks.herdr.sabre.orbit')
        ->not->toContain('attacker.test')
        ->not->toContain('good.test')
        ->not->toContain('script-src *')
        ->not->toContain('wss: ');
});

it('allows the local Vite dev server and Agentation sync server', function (): void {
    expect(developmentPolicy()->getContents())
        ->toContain('http://localhost:*')
        ->toContain('http://127.0.0.1:*')
        ->toContain('ws://localhost:*');
});

it('emits no IPv6 literals, which browsers reject as invalid CSP sources', function (): void {
    expect(developmentPolicy()->getContents())->not->toContain('[');
});

it('stays out of the way in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    expect(developmentPolicy()->isEmpty())->toBeTrue();
});

it('does not emit a hostless origin when APP_URL cannot be parsed', function (): void {
    config(['app.url' => '://']);

    expect(developmentPolicy()->getContents())
        ->not->toContain('https://:*')
        ->not->toContain('http://:*')
        ->toContain('https://localhost:*');
});
