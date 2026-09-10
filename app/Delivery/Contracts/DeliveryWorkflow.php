<?php

declare(strict_types=1);

namespace App\Delivery\Contracts;

use App\Delivery\Workflow\Phase;
use App\Delivery\Workflow\Transition;
use App\Delivery\Workflow\ValidatedReceipt;
use App\Models\Delivery;

interface DeliveryWorkflow
{
    public function initialPhase(Delivery $delivery): Phase;

    public function phase(string $name): Phase;

    public function nextPhase(Delivery $delivery, ValidatedReceipt $receipt): Transition;
}
