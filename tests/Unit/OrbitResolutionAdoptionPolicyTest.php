<?php

use App\Delivery\Workflow\OrbitResolutionAdoptionPolicy;
use App\Models\PhaseRun;
use App\Models\Receipt;

function resolutionPolicyPhase(): PhaseRun
{
    return (new PhaseRun)->forceFill([
        'input' => [
            'pr_review_receipt' => ['phase' => 'pr_review'],
        ],
    ]);
}

/** @param array<string, mixed> $proposal */
function resolutionPolicyReceipt(array $proposal): Receipt
{
    return (new Receipt)->forceFill([
        'id' => 25,
        'payload' => ['resolution' => $proposal],
    ]);
}

it('adopts only requirement-free proposals that resume the stopped phase', function () {
    $proposal = [
        'schema' => 1,
        'resume_phase' => 'implementing',
        'required_adrs' => [],
        'human_decisions' => [],
        'issue_changes' => [],
        'plan_changes' => [],
    ];

    $adoption = app(OrbitResolutionAdoptionPolicy::class)->assess(
        resolutionPolicyPhase(),
        resolutionPolicyReceipt($proposal),
        0,
    );

    expect($adoption->adopt)->toBeTrue()
        ->and($adoption->expectedResumePhase)->toBe('implementing')
        ->and($adoption->requirements)->toBe([])
        ->and($adoption->reason)->toBe('Eligible for automatic adoption into implementing; adoption is pending.');
});

it('requires a decision for issue or plan changes and for a different restart phase', function () {
    $proposal = [
        'schema' => 1,
        'resume_phase' => 'planning',
        'required_adrs' => [],
        'human_decisions' => [],
        'issue_changes' => ['Clarify acceptance.'],
        'plan_changes' => ['Revise the runtime plan.'],
    ];

    $adoption = app(OrbitResolutionAdoptionPolicy::class)->assess(
        resolutionPolicyPhase(),
        resolutionPolicyReceipt($proposal),
        0,
    );

    expect($adoption->adopt)->toBeFalse()
        ->and($adoption->expectedResumePhase)->toBe('implementing')
        ->and($adoption->requirements)->toBe([
            'issue_changes: Clarify acceptance.',
            'plan_changes: Revise the runtime plan.',
        ]);
});

it('preserves the two-adoption budget', function () {
    $proposal = [
        'schema' => 1,
        'resume_phase' => 'implementing',
        'required_adrs' => [],
        'human_decisions' => [],
        'issue_changes' => [],
        'plan_changes' => [],
    ];

    $adoption = app(OrbitResolutionAdoptionPolicy::class)->assess(
        resolutionPolicyPhase(),
        resolutionPolicyReceipt($proposal),
        2,
    );

    expect($adoption->adopt)->toBeFalse()
        ->and($adoption->reason)->toBe('The automatic resolution adoption budget is exhausted.');
});
