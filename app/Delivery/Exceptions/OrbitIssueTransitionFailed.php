<?php

declare(strict_types=1);

namespace App\Delivery\Exceptions;

use RuntimeException;
use Throwable;

final class OrbitIssueTransitionFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $ambiguous = false,
        public readonly ?Throwable $mutationFailure = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
