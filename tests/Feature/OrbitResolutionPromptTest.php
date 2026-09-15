<?php

use App\Delivery\Workflow\OrbitFeatureWorkflow;

it('preserves the exact original resolution prompt for retained dispatches', function (string $method, string $hash) {
    $prompt = app(OrbitFeatureWorkflow::class)->{$method}(
        'ORB-1', '/orbit', '/orbit-worktree', 1, 2, 3, 'submit-receipt', ['source' => 'evidence'], 1,
    );

    expect(hash('sha256', $prompt))->toBe($hash);
})->with([
    ['planResolutionPrompt', '80ae8f84e5f571de4e0a61061a3f916d926fb227d815d4681bfaa23e1ac174d3'],
    ['pullRequestResolutionPrompt', '73e5dc3d4403f94c7099e727825d4feff6a09f36f3b4c2e8f321e622fa4d2611'],
]);

it('loads the Orbit resolver from Commander for new dispatches', function (string $method) {
    $prompt = app(OrbitFeatureWorkflow::class)->{$method}(
        'ORB-1', '/orbit', '/orbit-worktree', 1, 2, 3, 'submit-receipt', ['source' => 'evidence'],
    );

    $skill = base_path('.agents/projects/orbit/skills/resolve-pipeline-issues/SKILL.md');

    expect(is_readable($skill))->toBeTrue()
        ->and($prompt)->toContain($skill)
        ->not->toContain('/orbit/.agents/skills/resolve-pipeline-issues/SKILL.md');
})->with(['planResolutionPrompt', 'pullRequestResolutionPrompt']);
