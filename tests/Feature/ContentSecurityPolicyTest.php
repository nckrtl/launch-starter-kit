<?php

use App\Support\Csp\Presets\Development;
use Spatie\Csp\Policy;

function developmentPolicy(): Policy
{
    $policy = new Policy;

    (new Development)->configure($policy);

    return $policy;
}

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
