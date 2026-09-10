<?php

declare(strict_types=1);

namespace App\Delivery\Contracts;

interface ProjectConfigUpcaster
{
    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function upcast(array $config): array;
}
