<?php

declare(strict_types=1);

namespace App\Delivery\Contracts;

use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\ReconciledOrbitPrimaryCheckout;

interface OrbitPrimaryCheckoutReconciler
{
    public function reconcilePrimaryCheckout(
        OrbitProjectConfig $config,
        string $mergeCommitSha,
    ): ReconciledOrbitPrimaryCheckout;
}
