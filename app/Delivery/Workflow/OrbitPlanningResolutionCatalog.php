<?php

declare(strict_types=1);

namespace App\Delivery\Workflow;

use App\Delivery\Data\OrbitPlanningResolutionCorrection;
use Illuminate\Filesystem\Filesystem;

final readonly class OrbitPlanningResolutionCatalog
{
    public function __construct(private Filesystem $files) {}

    public function has(string $issueKey): bool
    {
        $entries = config('commander.delivery.orbit_planning_resolutions', []);

        return is_array($entries) && array_key_exists($issueKey, $entries);
    }

    public function find(
        string $issueId,
        string $issueKey,
        string $receiptHash,
        string $currentContractHash,
    ): ?OrbitPlanningResolutionCorrection {
        $entries = config('commander.delivery.orbit_planning_resolutions', []);
        $entry = is_array($entries) ? ($entries[$issueKey] ?? null) : null;

        if (! is_array($entry)
            || array_keys($entry) !== [
                'issue_id',
                'resolution_receipt_sha256',
                'current_contract_sha256',
                'corrected_contract_sha256',
                'corrected_description',
                'corrected_description_sha256',
            ]
            || ($entry['issue_id'] ?? null) !== $issueId
            || ($entry['resolution_receipt_sha256'] ?? null) !== $receiptHash
            || ($entry['current_contract_sha256'] ?? null) !== $currentContractHash) {
            return null;
        }

        $path = $entry['corrected_description'] ?? null;
        $correctedContractHash = $entry['corrected_contract_sha256'] ?? null;
        $expectedHash = $entry['corrected_description_sha256'] ?? null;

        if (! is_string($path) || ! str_starts_with($path, resource_path('delivery/orbit/planning-resolutions/'))
            || ! $this->files->isFile($path) || is_link($path)
            || ! is_string($correctedContractHash)
            || preg_match('/^[a-f0-9]{64}$/', $correctedContractHash) !== 1
            || ! is_string($expectedHash) || preg_match('/^[a-f0-9]{64}$/', $expectedHash) !== 1) {
            return null;
        }

        $description = rtrim($this->files->get($path), "\r\n");

        if ($description === '' || ! hash_equals($expectedHash, hash('sha256', $description))) {
            return null;
        }

        return new OrbitPlanningResolutionCorrection(
            issueId: $issueId,
            issueKey: $issueKey,
            resolutionReceiptHash: $receiptHash,
            currentContractHash: $currentContractHash,
            correctedContractHash: $correctedContractHash,
            correctedDescription: $description,
            correctedDescriptionHash: $expectedHash,
        );
    }
}
