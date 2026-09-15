<?php

declare(strict_types=1);

namespace App\Models;

use App\Tasks\Enums\TaskReviewVerdict;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $task_run_id
 * @property int $round
 * @property string|null $tree_sha
 * @property array<string, mixed> $output
 * @property CarbonImmutable $requested_at
 * @property TaskReviewVerdict|null $verdict
 * @property string|null $summary
 * @property string|null $evidence_ref
 * @property CarbonImmutable|null $reviewed_at
 * @property-read TaskRun $taskRun
 */
final class TaskRunReview extends Model
{
    protected $fillable = ['task_run_id', 'round', 'tree_sha', 'output', 'requested_at', 'verdict', 'summary', 'evidence_ref', 'reviewed_at'];

    protected static function booted(): void
    {
        self::updating(function (self $review): void {
            if ($review->isDirty(['task_run_id', 'round', 'tree_sha', 'output', 'requested_at'])
                || ($review->getRawOriginal('verdict') !== null && $review->isDirty())) {
                throw new LogicException('Review submissions and recorded verdicts are immutable.');
            }
        });

        self::deleting(fn (): never => throw new LogicException('Task run reviews retain acceptance history.'));
    }

    protected function casts(): array
    {
        return ['verdict' => TaskReviewVerdict::class, 'output' => 'array', 'requested_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<TaskRun, $this> */
    public function taskRun(): BelongsTo
    {
        return $this->belongsTo(TaskRun::class);
    }
}
