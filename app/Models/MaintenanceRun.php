<?php

declare(strict_types=1);

namespace App\Models;

use App\Delivery\Enums\MaintenanceRunStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_orchestration_id
 * @property int|null $delivery_id
 * @property string $kind
 * @property MaintenanceRunStatus $status
 * @property int $attempt
 * @property string $idempotency_key
 * @property array<string, mixed>|null $input
 * @property array<string, mixed>|null $result
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property-read ProjectOrchestration $projectOrchestration
 * @property-read Delivery|null $delivery
 */
final class MaintenanceRun extends Model
{
    protected function casts(): array
    {
        return [
            'status' => MaintenanceRunStatus::class,
            'input' => 'array',
            'result' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ProjectOrchestration, $this> */
    public function projectOrchestration(): BelongsTo
    {
        return $this->belongsTo(ProjectOrchestration::class);
    }

    /** @return BelongsTo<Delivery, $this> */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }
}
