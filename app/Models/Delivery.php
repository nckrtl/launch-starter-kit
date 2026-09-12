<?php

declare(strict_types=1);

namespace App\Models;

use App\Delivery\Enums\DeliveryStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int $project_orchestration_id
 * @property string $external_issue_provider
 * @property string $external_issue_id
 * @property string|null $external_issue_key
 * @property string $workflow_type
 * @property int $workflow_version
 * @property DeliveryStatus $status
 * @property string $current_phase
 * @property string|null $active_issue_key
 * @property string|null $branch
 * @property string|null $worktree_path
 * @property string|null $candidate_sha
 * @property int|null $pull_request_number
 * @property string|null $pull_request_url
 * @property array<string, mixed>|null $completion_details
 * @property array<string, mixed>|null $failure_details
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $paused_at
 * @property-read ProjectOrchestration $projectOrchestration
 * @property-read Collection<int, PhaseRun> $phaseRuns
 * @property-read Collection<int, ExternalEvent> $externalEvents
 * @property-read Collection<int, MaintenanceRun> $maintenanceRuns
 */
final class Delivery extends Model
{
    protected static function booted(): void
    {
        self::saving(function (self $delivery): void {
            $attribute = $delivery->getAttribute('status');
            $status = $attribute instanceof DeliveryStatus ? $attribute : DeliveryStatus::Queued;

            $delivery->active_issue_key = $status->isTerminal()
                ? null
                : hash('sha256', implode('|', [
                    (string) $delivery->project_orchestration_id,
                    $delivery->external_issue_provider,
                    $delivery->external_issue_id,
                ]));
        });
    }

    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'completion_details' => 'array',
            'failure_details' => 'array',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'paused_at' => 'immutable_datetime',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNotNull('active_issue_key');
    }

    /** @param Builder<self> $query */
    public function scopeOccupiesCapacity(Builder $query): void
    {
        $query->active()->where(function (Builder $query): void {
            $query->where('status', '!=', DeliveryStatus::Blocked)
                ->orWhereNull('failure_details->code')
                ->orWhere('failure_details->code', '!=', 'resolution_decision_required');
        });
    }

    /** @return BelongsTo<ProjectOrchestration, $this> */
    public function projectOrchestration(): BelongsTo
    {
        return $this->belongsTo(ProjectOrchestration::class);
    }

    /** @return HasMany<PhaseRun, $this> */
    public function phaseRuns(): HasMany
    {
        return $this->hasMany(PhaseRun::class);
    }

    /** @return HasMany<ExternalEvent, $this> */
    public function externalEvents(): HasMany
    {
        return $this->hasMany(ExternalEvent::class);
    }

    /** @return HasMany<MaintenanceRun, $this> */
    public function maintenanceRuns(): HasMany
    {
        return $this->hasMany(MaintenanceRun::class);
    }
}
