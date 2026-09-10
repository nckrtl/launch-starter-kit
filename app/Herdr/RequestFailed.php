<?php

namespace App\Herdr;

use RuntimeException;

final class RequestFailed extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $errorCode = null)
    {
        parent::__construct($message);
    }
}
