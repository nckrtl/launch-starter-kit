<?php

declare(strict_types=1);

namespace App\Delivery\Contracts;

interface OrbitDeliveryLoopStarter
{
    public function start(string $projectId, string $issueKey): void;
}
