<?php

declare(strict_types=1);

namespace App\Delivery\Data;

final readonly class CandidateCheck
{
    public function __construct(
        public string $receiptPath,
        public string $candidateSha,
        public string $treeSha,
    ) {}
}
