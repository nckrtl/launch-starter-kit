<?php

declare(strict_types=1);

namespace App\Delivery\Contracts;

use App\Delivery\Data\RequestedOrbitMainCacheRefresh;

interface OrbitMainCacheRefreshRequester
{
    public function request(string $repository): RequestedOrbitMainCacheRefresh;
}
