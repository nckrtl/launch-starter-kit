<?php

declare(strict_types=1);

namespace App\Delivery\Workflow;

use App\Delivery\Data\OrbitResolutionAdoption;
use App\Models\PhaseRun;
use App\Models\Receipt;

final readonly class OrbitResolutionAdoptionPolicy
{
    private const int AUTOMATIC_ADOPTION_LIMIT = 2;

    public function assess(PhaseRun $phase, Receipt $receipt, int $priorAdoptions): OrbitResolutionAdoption
    {
        $proposal = $receipt->payload['resolution'] ?? null;
        $input = $phase->input;
        $origin = is_array($input) && is_array($input['pr_review_receipt'] ?? null)
            ? ($input['pr_review_receipt']['phase'] ?? null)
            : null;
        $planOrigin = is_array($input)
            && (is_array($input['planning_receipt'] ?? null)
                || is_array($input['plan_review_receipt'] ?? null));
        $expected = match (true) {
            $origin === OrbitFeatureWorkflow::PR_REVIEW_PHASE => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
            $planOrigin => OrbitFeatureWorkflow::INITIAL_PHASE,
            default => '',
        };
        $canAdoptOrigin = $origin === OrbitFeatureWorkflow::PR_REVIEW_PHASE;
        $requirements = [];

        if (is_array($proposal)) {
            foreach (['required_adrs', 'human_decisions', 'issue_changes', 'plan_changes'] as $field) {
                $items = $proposal[$field] ?? null;

                if (is_array($items)) {
                    foreach ($items as $item) {
                        if (is_string($item)) {
                            $requirements[] = $field.': '.$item;
                        }
                    }
                }
            }
        }

        $resume = is_array($proposal) && is_string($proposal['resume_phase'] ?? null)
            ? $proposal['resume_phase']
            : '';
        $alreadyAdopted = is_array($phase->output)
            && ($phase->output['receipt_id'] ?? null) === $receipt->id
            && ($phase->output['adopted'] ?? null) === true;
        $adopt = $requirements === []
            && $canAdoptOrigin
            && $resume === $expected
            && ($alreadyAdopted || $priorAdoptions < self::AUTOMATIC_ADOPTION_LIMIT);
        $reason = match (true) {
            $requirements !== [] => 'Resolution requirements need an explicit decision or contract change.',
            $expected === '' => 'The resolution origin cannot be resumed automatically.',
            ! $canAdoptOrigin => 'Planning resolutions require an explicit decision before adoption.',
            $resume !== $expected => 'The proposed resume phase does not match the stopped phase.',
            ! $alreadyAdopted && $priorAdoptions >= self::AUTOMATIC_ADOPTION_LIMIT => 'The automatic resolution adoption budget is exhausted.',
            default => "Eligible for automatic adoption into {$expected}; adoption is pending.",
        };

        return new OrbitResolutionAdoption($adopt, $expected, $requirements, $reason);
    }
}
