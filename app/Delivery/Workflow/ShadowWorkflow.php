<?php

declare(strict_types=1);

namespace App\Delivery\Workflow;

use App\Delivery\Contracts\DeliveryWorkflow;
use App\Models\Delivery;
use InvalidArgumentException;

final readonly class ShadowWorkflow implements DeliveryWorkflow
{
    public const string TYPE = 'herdr-shadow';

    public const int VERSION = 1;

    public function initialPhase(Delivery $delivery): Phase
    {
        return $this->phase('herdr_test');
    }

    public function phase(string $name): Phase
    {
        return match ($name) {
            'herdr_test' => new Phase(
                name: 'herdr_test',
                agentRole: 'test',
                prompt: 'Do not change source files. Run the exact harmless receipt command below and then stop.',
            ),
            'herdr_confirm' => new Phase(
                name: 'herdr_confirm',
                agentRole: 'test-reviewer',
                prompt: 'Do not change source files. Run the exact harmless receipt command below to confirm the contract and then stop.',
            ),
            default => throw new InvalidArgumentException("Unknown shadow workflow phase [{$name}]."),
        };
    }

    public function nextPhase(Delivery $delivery, ValidatedReceipt $receipt): Transition
    {
        return match ($receipt->receipt->phaseRun->phase_name) {
            'herdr_test' => Transition::to($this->phase('herdr_confirm')),
            'herdr_confirm' => Transition::complete(),
            default => throw new InvalidArgumentException('The receipt does not belong to this workflow.'),
        };
    }
}
