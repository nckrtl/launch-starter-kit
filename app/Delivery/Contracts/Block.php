<?php

declare(strict_types=1);

namespace App\Delivery\Contracts;

use App\Delivery\Workflow\BlockContext;
use App\Delivery\Workflow\BlockResult;

interface Block
{
    public function execute(BlockContext $context): BlockResult;
}
